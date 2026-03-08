<?php

use App\Http\Controllers\Admin\AdminActionController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Admin web routes (Inertia)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('airports', [DashboardController::class, 'airports'])->name('airports.index');
    Route::get('airports/{airport}', [DashboardController::class, 'show'])->name('airports.show');

    // Operator actions — form POSTs, redirect back with flash
    Route::post('sources/{source}/scrape', [AdminActionController::class, 'triggerScrape'])->name('sources.scrape');
    Route::post('sources/{source}/parser-versions/{parserVersion}/activate', [AdminActionController::class, 'activateParserVersion'])->name('parser-versions.activate');
    Route::patch('failures/{failure}', [AdminActionController::class, 'updateFailure'])->name('failures.update');
});
