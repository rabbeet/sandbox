<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Airport Platform Scheduler
|--------------------------------------------------------------------------
*/

// Dispatch scrape jobs every minute for all due active sources
Schedule::command('scrapes:schedule')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Hourly artifact cleanup
Schedule::command('scrapes:cleanup')->hourly();

// Every 5 minutes: recheck open parser failures
Schedule::command('repairs:recheck-open-failures')->everyFiveMinutes();

// Every 5 minutes: check flight identity layer health
// Emits structured log event=flight_identity_health with per-counter fields.
// Non-zero exit code when orphaned_snapshots / duplicate_instances / duplicate_currents > 0.
Schedule::command('flights:monitor-identity-health')
    ->everyFiveMinutes()
    ->runInBackground()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('flight_identity_health: scheduled check returned non-zero exit', [
            'event' => 'flight_identity_health_check_failed',
        ]);
    });
