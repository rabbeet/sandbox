<?php

namespace App\Domain\Scraping\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NormalizeScrapePayloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int $scrapeJobId,
        public readonly array $rows,
    ) {}

    public function handle(): void
    {
        // Phase 3: normalization pipeline, flights_current updater, flight_changes
        Log::info('NormalizeScrapePayloadJob: stub - not yet implemented', [
            'scrape_job_id' => $this->scrapeJobId,
            'row_count'     => count($this->rows),
        ]);
    }
}
