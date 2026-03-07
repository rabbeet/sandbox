<?php

use App\Http\Controllers\Admin\AirportController;
use App\Http\Controllers\Admin\AirportSourceController;
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

    // Airport Sources (nested under airports)
    Route::apiResource('airports.sources', AirportSourceController::class)
        ->shallow();
});
