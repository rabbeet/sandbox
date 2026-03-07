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
