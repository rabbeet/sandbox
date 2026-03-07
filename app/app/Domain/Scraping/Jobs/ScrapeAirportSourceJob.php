<?php

namespace App\Domain\Scraping\Jobs;

use App\Domain\Airports\Models\AirportSource;
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

            // Dispatch normalization job with the scraped rows
            NormalizeScrapePayloadJob::dispatch($scrapeJob->id, $result['rows'])
                ->onQueue('normalize');

        } catch (\RuntimeException $e) {
            $durationMs = (int) ($startedAt->diffInMilliseconds(now()));
            $errorCode  = $this->extractErrorCode($e->getMessage());

            $this->markFailed($scrapeJob, $errorCode, $e->getMessage(), $durationMs);

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

    private function extractErrorCode(string $message): string
    {
        // Runtime errors start with a code prefix: "error_code: message"
        if (preg_match('/^([a-z_]+(?:_\d+)?):/', $message, $m)) {
            return $m[1];
        }
        return 'scraper_error';
    }
}
