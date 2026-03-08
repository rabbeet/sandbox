<?php

namespace App\Domain\Repairs\Jobs;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Opens a ParserFailure record for a completed scrape job that triggered a
 * soft-failure condition (zero rows, low quality score, row-count drop, high
 * null rate). Hard failures (HTTP errors, timeouts) are recorded by
 * ScrapeAirportSourceJob directly and do not go through this job.
 *
 * SEVERITY ESCALATION (soft failures only)
 * ----------------------------------------
 * Severity is based on the number of consecutive failures (open + investigating)
 * for the same airport source at the time this job runs:
 *
 *   consecutive  │ severity
 *   ─────────────┼──────────
 *   1            │ low
 *   2            │ low
 *   3–4          │ medium
 *   5–6          │ high
 *   7+           │ critical
 *
 * "Consecutive" means consecutive in the DB — it counts ParserFailure rows with
 * status IN ('open', 'investigating') for this source, ordered by created_at DESC.
 * It stops counting at the first row with status IN ('repaired', 'ignored').
 *
 * OPERATIONAL LIMITS
 * ------------------
 * - This job does not de-duplicate: if the same source fires two soft failures in
 *   quick succession (e.g. two scrapes complete before this job runs), two
 *   ParserFailure rows are created. This is intentional — each scrape result is
 *   independently auditable.
 * - The consecutive-count heuristic counts failures since the last repair/ignore,
 *   not since a true operational change. If an operator marks a failure 'ignored'
 *   without fixing the underlying parser, the counter resets. This is a known
 *   trade-off: false resets are preferable to permanently escalating severity for
 *   acknowledged noise.
 */
class OpenParserFailureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(
        public readonly int $scrapeJobId,
        public readonly int $airportSourceId,
        public readonly string $errorCode,
        public readonly string $errorMessage,
        public readonly array $failureDetails = [],
    ) {}

    public function handle(): void
    {
        $scrapeJob = ScrapeJob::find($this->scrapeJobId);
        $source    = AirportSource::find($this->airportSourceId);

        if (! $scrapeJob || ! $source) {
            Log::warning('OpenParserFailureJob: missing scrape_job or source', [
                'scrape_job_id'     => $this->scrapeJobId,
                'airport_source_id' => $this->airportSourceId,
            ]);
            return;
        }

        $consecutive = $this->countConsecutiveFailures($source);
        $severity    = $this->deriveSeverity($consecutive + 1); // +1 for this new failure

        ParserFailure::create([
            'airport_source_id' => $source->id,
            'parser_version_id' => $scrapeJob->parser_version_id,
            'scrape_job_id'     => $scrapeJob->id,
            'failure_type'      => 'soft',
            'severity'          => $severity,
            'error_code'        => $this->errorCode,
            'error_message'     => $this->errorMessage,
            'failure_details'   => $this->failureDetails,
            'status'            => 'open',
        ]);

        Log::warning('OpenParserFailureJob: parser failure opened', [
            'event'             => 'parser_failure_opened',
            'scrape_job_id'     => $scrapeJob->id,
            'airport_source_id' => $source->id,
            'error_code'        => $this->errorCode,
            'consecutive'       => $consecutive + 1,
            'severity'          => $severity,
        ]);
    }

    /**
     * Count consecutive open/investigating failures for this source, stopping at
     * the first repaired or ignored row (in reverse chronological order).
     *
     * This is an O(n) scan in PHP rather than a single SQL expression, keeping
     * the query simple and the logic easy to test. Sources typically have low
     * failure counts (< 20 open failures before a repair ticket is filed).
     */
    private function countConsecutiveFailures(AirportSource $source): int
    {
        $failures = ParserFailure::where('airport_source_id', $source->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->pluck('status');

        $count = 0;
        foreach ($failures as $status) {
            if (in_array($status, ['open', 'investigating'], true)) {
                $count++;
            } else {
                break; // repaired or ignored resets the streak
            }
        }

        return $count;
    }

    private function deriveSeverity(int $consecutiveCount): string
    {
        return match (true) {
            $consecutiveCount >= 7 => 'critical',
            $consecutiveCount >= 5 => 'high',
            $consecutiveCount >= 3 => 'medium',
            default                => 'low',
        };
    }
}
