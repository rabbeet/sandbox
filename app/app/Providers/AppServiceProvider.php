<?php

namespace App\Providers;

use App\Domain\Repairs\Models\ParserFailure;
use App\Domain\Repairs\Observers\ParserFailureObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ParserFailure::observe(ParserFailureObserver::class);
    }
}
