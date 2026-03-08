<?php

namespace App\Domain\Scraping\Jobs;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Repairs\Jobs\OpenParserFailureJob;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Models\ScrapeArtifact;
use App\Domain\Scraping\Models\ScrapeJob;
use App\Domain\Scraping\Services\ScrapeRuntimeClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeAirportSourceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 150;

    public function __construct(
        public readonly int $scrapeJobId,
        public readonly int $airportSourceId,
    ) {}

    public function handle(ScrapeRuntimeClient $client): void
    {
        $scrapeJob = ScrapeJob::find($this->scrapeJobId);

        if (! $scrapeJob) {
            Log::warning('ScrapeAirportSourceJob: ScrapeJob not found', ['id' => $this->scrapeJobId]);
            return;
        }

        if ($scrapeJob->status !== 'pending') {
            Log::info('ScrapeAirportSourceJob: skipping non-pending job', [
                'id'     => $this->scrapeJobId,
                'status' => $scrapeJob->status,
            ]);
            return;
        }

        $source = AirportSource::with(['airport', 'activeParserVersion'])->find($this->airportSourceId);

        if (! $source || ! $source->activeParserVersion) {
            $this->markFailed($scrapeJob, 'invalid_parser_definition', 'No active parser version for source');
            return;
        }

        $startedAt = now();
        $scrapeJob->update([
            'status'            => 'running',
            'started_at'        => $startedAt,
            'parser_version_id' => $source->activeParserVersion->id,
        ]);

        try {
            $result = $client->scrape($source, $source->activeParserVersion, $scrapeJob->id);

            $durationMs = (int) ($startedAt->diffInMilliseconds(now()));

            $scrapeJob->update([
                'status'       => 'success',
                'completed_at' => now(),
                'duration_ms'  => $durationMs,
                'row_count'    => $result['row_count'],
                'quality_score'=> $result['quality_score'],
            ]);

            $this->persistArtifacts($scrapeJob, $result['artifacts']);

            Log::info('ScrapeAirportSourceJob: success', [
                'scrape_job_id' => $scrapeJob->id,
                'source_id'     => $source->id,
                'duration_ms'   => $durationMs,
                'row_count'     => $result['row_count'],
                'quality_score' => $result['quality_score'],
            ]);

            // Soft-failure detection: check for anomalies that indicate a structural
            // parser problem even though the HTTP request succeeded.
            $this->detectSoftFailures($scrapeJob, $source, $result);

            // Dispatch normalization job with the scraped rows
            NormalizeScrapePayloadJob::dispatch($scrapeJob->id, $result['rows'])
                ->onQueue('normalize');

        } catch (\RuntimeException $e) {
            $durationMs = (int) ($startedAt->diffInMilliseconds(now()));
            $errorCode  = $this->extractErrorCode($e->getMessage());

            $this->markFailed($scrapeJob, $errorCode, $e->getMessage(), $durationMs);
            $this->openHardParserFailure($scrapeJob, $source, $errorCode, $e->getMessage());

            Log::error('ScrapeAirportSourceJob: failed', [
                'scrape_job_id' => $scrapeJob->id,
                'source_id'     => $source->id,
                'error_code'    => $errorCode,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $scrapeJob = ScrapeJob::find($this->scrapeJobId);
        if ($scrapeJob && $scrapeJob->status === 'running') {
            $this->markFailed($scrapeJob, 'queue_job_failed', $exception->getMessage());
        }

        // Open a hard failure record so operators can see unhandled exceptions in
        // the failures list. This path is rare (RuntimeException is caught above);
        // it fires for OOM, DB errors, or other unexpected Throwables.
        $source = AirportSource::find($this->airportSourceId);
        if ($scrapeJob && $source) {
            $this->openHardParserFailure($scrapeJob, $source, 'queue_job_failed', $exception->getMessage());
        }
    }

    private function markFailed(ScrapeJob $scrapeJob, string $errorCode, string $errorMessage, ?int $durationMs = null): void
    {
        $scrapeJob->update([
            'status'        => 'failed',
            'completed_at'  => now(),
            'duration_ms'   => $durationMs,
            'error_code'    => substr($errorCode, 0, 64),
            'error_message' => $errorMessage,
        ]);
    }

    private function persistArtifacts(ScrapeJob $scrapeJob, array $artifacts): void
    {
        $validTypes = ['html', 'screenshot', 'har', 'raw_response'];

        foreach ($artifacts as $artifact) {
            $type = $artifact['type'] ?? 'raw_response';
            if (! in_array($type, $validTypes, true)) {
                $type = 'raw_response';
            }

            if (empty($artifact['storage_path'])) {
                continue;
            }

            ScrapeArtifact::create([
                'scrape_job_id' => $scrapeJob->id,
                'artifact_type' => $type,
                'storage_path'  => $artifact['storage_path'],
                'size_bytes'    => $artifact['size_bytes'] ?? null,
                'expires_at'    => $artifact['expires_at'] ?? null,
            ]);
        }
    }

    /**
     * Soft-failure detection: inspect the result of a technically-successful scrape
     * for conditions that indicate a structural parser problem.
     *
     * CONDITIONS CHECKED
     * ------------------
     * 1. zero_rows          — row_count is 0. The scraper returned a valid response but
     *                         extracted nothing. Either the page layout changed or the
     *                         airport genuinely has no flights (rare, and only plausible
     *                         for very small airports at off-peak hours).
     *
     * 2. low_quality_score  — quality_score < SCRAPER_QUALITY_THRESHOLD (default 0.5).
     *                         More than half the rows are structurally incomplete. This
     *                         threshold is a heuristic; a score above threshold does not
     *                         guarantee data correctness — it means most rows have required
     *                         fields present and non-null.
     *
     * Each condition that fires dispatches an independent OpenParserFailureJob. If both
     * fire for the same scrape, two failure records are opened. This is intentional:
     * zero_rows and low_quality_score may have different root causes and may resolve
     * independently.
     *
     * CONDITIONS NOT CHECKED HERE
     * ---------------------------
     * - Row-count drop >50% vs. recent average: this requires a historical baseline query
     *   and is deferred to a separate DetectAnomaliesJob (not yet implemented). It is
     *   called out in the operational runbook as a future addition.
     * - Null rate >20% per field: also requires baseline comparison; deferred.
     *
     * OPERATIONAL LIMIT
     * -----------------
     * zero_rows is a known false-positive risk for airports with infrequent service.
     * If an airport consistently triggers zero_rows during legitimate quiet periods,
     * consider either raising the threshold or adding a time-of-day guard. No such
     * guard is implemented here; operators should use the 'ignored' failure status to
     * acknowledge known patterns.
     */
    private function detectSoftFailures(ScrapeJob $scrapeJob, AirportSource $source, array $result): void
    {
        $rowCount     = (int) ($result['row_count'] ?? 0);
        $qualityScore = (float) ($result['quality_score'] ?? 0.0);
        $threshold    = (float) config('scraper.quality_threshold', 0.5);

        if ($rowCount === 0) {
            OpenParserFailureJob::dispatch(
                $scrapeJob->id,
                $source->id,
                'zero_rows',
                'Scrape completed successfully but returned zero rows.',
                [
                    'row_count'     => $rowCount,
                    'quality_score' => $qualityScore,
                ],
            )->onQueue('repairs');

            Log::warning('ScrapeAirportSourceJob: soft failure — zero rows', [
                'event'         => 'soft_failure',
                'error_code'    => 'zero_rows',
                'scrape_job_id' => $scrapeJob->id,
                'source_id'     => $source->id,
            ]);
        }

        if ($rowCount > 0 && $qualityScore < $threshold) {
            OpenParserFailureJob::dispatch(
                $scrapeJob->id,
                $source->id,
                'low_quality_score',
                "Quality score {$qualityScore} is below threshold {$threshold}.",
                [
                    'row_count'      => $rowCount,
                    'quality_score'  => $qualityScore,
                    'threshold'      => $threshold,
                ],
            )->onQueue('repairs');

            Log::warning('ScrapeAirportSourceJob: soft failure — low quality score', [
                'event'         => 'soft_failure',
                'error_code'    => 'low_quality_score',
                'scrape_job_id' => $scrapeJob->id,
                'source_id'     => $source->id,
                'quality_score' => $qualityScore,
                'threshold'     => $threshold,
            ]);
        }
    }

    /**
     * Create a ParserFailure record for a hard failure (runtime HTTP error,
     * connection timeout, or unhandled exception). Hard failures always open at
     * severity=critical because they indicate the scraper could not produce any
     * data — there is nothing to partially accept.
     *
     * HTTP status is extracted from error codes like scraper_runtime_http_500
     * and stored in failure_details for operator inspection.
     */
    private function openHardParserFailure(
        ScrapeJob $scrapeJob,
        AirportSource $source,
        string $errorCode,
        string $rawMessage,
    ): void {
        $httpStatus = null;
        if (preg_match('/scraper_runtime_http_(\d{3})/', $errorCode, $m)) {
            $httpStatus = (int) $m[1];
        }

        ParserFailure::create([
            'airport_source_id' => $source->id,
            'parser_version_id' => $scrapeJob->parser_version_id,
            'scrape_job_id'     => $scrapeJob->id,
            'failure_type'      => 'hard',
            'severity'          => 'critical',
            'error_code'        => substr($errorCode, 0, 64),
            'error_message'     => mb_substr($rawMessage, 0, 2000),
            'failure_details'   => array_filter(['http_status' => $httpStatus]),
            'status'            => 'open',
        ]);

        Log::warning('ScrapeAirportSourceJob: hard parser failure opened', [
            'event'             => 'hard_parser_failure_opened',
            'scrape_job_id'     => $scrapeJob->id,
            'airport_source_id' => $source->id,
            'error_code'        => $errorCode,
            'http_status'       => $httpStatus,
        ]);
    }

    private function extractErrorCode(string $message): string
    {
        // Runtime errors start with a code prefix: "error_code: message"
        if (preg_match('/^([a-z_]+(?:_\d+)?):/', $message, $m)) {
            return $m[1];
        }
        return 'scraper_error';
    }
}
