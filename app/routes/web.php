<?php

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
});
