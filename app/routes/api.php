<?php

use App\Http\Controllers\Admin\AirportController;
use App\Http\Controllers\Admin\AirportSourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    // Airports
    Route::apiResource('airports', AirportController::class);

    // Airport Sources (nested under airports)
    Route::apiResource('airports.sources', AirportSourceController::class)
        ->shallow();
});
