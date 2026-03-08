<?php

use App\Http\Controllers\Admin\AirportController;
use App\Http\Controllers\Admin\AirportSourceController;
use App\Http\Controllers\Admin\ParserFailureController;
use App\Http\Controllers\Admin\ParserVersionController;
use App\Http\Controllers\Api\FlightController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public flight data API (no auth required)
|--------------------------------------------------------------------------
*/
Route::get('flights/search', [FlightController::class, 'search']);
Route::get('flights/{flight}', [FlightController::class, 'show']);
Route::get('airports/{iata}/departures', [FlightController::class, 'departures']);
Route::get('airports/{iata}/arrivals', [FlightController::class, 'arrivals']);
Route::get('disruptions', [FlightController::class, 'disruptions']);

/*
|--------------------------------------------------------------------------
| Authenticated admin API
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    // Airports
    Route::apiResource('airports', AirportController::class);

    // Airport Sources (nested under airports, shallow so source-only routes use /sources/{source})
    Route::apiResource('airports.sources', AirportSourceController::class)
        ->shallow();

    // Manual scrape trigger — POST /api/sources/{source}/scrape
    // Requires airport-sources.scrape permission. Returns 202 with scrape_job_id.
    Route::post('sources/{source}/scrape', [AirportSourceController::class, 'scrape'])
        ->name('sources.scrape');

    // Parser versions — nested under source (shallow: version-only routes use /parser-versions/{parserVersion})
    Route::get('sources/{source}/parser-versions', [ParserVersionController::class, 'index'])
        ->name('sources.parser-versions.index');
    Route::post('sources/{source}/parser-versions', [ParserVersionController::class, 'store'])
        ->name('sources.parser-versions.store');
    Route::post('sources/{source}/parser-versions/{parserVersion}/activate', [ParserVersionController::class, 'activate'])
        ->name('sources.parser-versions.activate');
    Route::post('sources/{source}/parser-versions/{parserVersion}/replay', [ParserVersionController::class, 'replay'])
        ->name('sources.parser-versions.replay');

    // Parser failures — listed under source; status updates use the failure id directly
    Route::get('sources/{source}/failures', [ParserFailureController::class, 'index'])
        ->name('sources.failures.index');
    Route::patch('failures/{failure}', [ParserFailureController::class, 'update'])
        ->name('failures.update');
});
