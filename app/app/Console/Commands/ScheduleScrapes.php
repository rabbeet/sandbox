<?php

namespace App\Console\Commands;

use App\Domain\Airports\Models\AirportSource;
use App\Domain\Scraping\Jobs\ScrapeAirportSourceJob;
use App\Domain\Scraping\Models\ScrapeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScheduleScrapes extends Command
{
    protected $signature = 'scrapes:schedule';
    protected $description = 'Schedule scrape jobs for all active airport sources that are due';

    // Airports with passenger volumes above this threshold use the high-priority queue
    private const HIGH_PRIORITY_QUEUE = 'scrape-high';
    private const DEFAULT_QUEUE = 'scrape-default';

    public function handle(): int
    {
        $sources = AirportSource::with(['airport', 'activeParserVersion'])
            ->where('is_active', true)
            ->whereNotNull('active_parser_version_id')
            ->get();

        $dispatched = 0;
        $skipped    = 0;

        foreach ($sources as $source) {
            $lockKey = "scrape_lock:source:{$source->id}";

            // Distributed lock: skip if another process is already handling this source
            $lock = Cache::lock($lockKey, 55); // TTL slightly under the 1-minute schedule

            if (! $lock->get()) {
                $this->line("  [skip] source {$source->id} - lock held");
                $skipped++;
                continue;
            }

            try {
                if (! $this->isDue($source)) {
                    $lock->release();
                    $skipped++;
                    continue;
                }

                $scrapeJob = ScrapeJob::create([
                    'airport_source_id' => $source->id,
                    'parser_version_id' => $source->activeParserVersion->id,
                    'status'            => 'pending',
                    'triggered_by'      => 'scheduler',
                ]);

                $queue = $this->resolveQueue($source);

                ScrapeAirportSourceJob::dispatch($scrapeJob->id, $source->id)
                    ->onQueue($queue);

                $this->line("  [ok] source {$source->id} ({$source->airport->iata}/{$source->board_type}) -> {$queue} job#{$scrapeJob->id}");

                Log::info('scrapes:schedule dispatched', [
                    'source_id'     => $source->id,
                    'scrape_job_id' => $scrapeJob->id,
                    'queue'         => $queue,
                ]);

                $dispatched++;
            } finally {
                $lock->release();
            }
        }

        $this->info("Dispatched {$dispatched} scrape job(s), skipped {$skipped}.");

        return self::SUCCESS;
    }

    private function isDue(AirportSource $source): bool
    {
        $lastJob = ScrapeJob::where('airport_source_id', $source->id)
            ->whereIn('status', ['pending', 'running', 'success'])
            ->latest('created_at')
            ->first();

        if (! $lastJob) {
            return true; // Never scraped
        }

        // Skip if still running or pending
        if (in_array($lastJob->status, ['pending', 'running'], true)) {
            return false;
        }

        $intervalMinutes = max(1, $source->scrape_interval_minutes);
        $nextDueAt = $lastJob->created_at->addMinutes($intervalMinutes);

        return now()->greaterThanOrEqualTo($nextDueAt);
    }

    private function resolveQueue(AirportSource $source): string
    {
        // Large/major airports (IATA codes of tier-1 hubs) use high-priority queue
        $highPriorityIata = config('scraper.high_priority_airports', [
            'JFK', 'LHR', 'CDG', 'DXB', 'LAX', 'ATL', 'ORD', 'HND', 'PEK', 'SIN',
        ]);

        if (in_array($source->airport->iata, $highPriorityIata, true)) {
            return self::HIGH_PRIORITY_QUEUE;
        }

        return self::DEFAULT_QUEUE;
    }
}
