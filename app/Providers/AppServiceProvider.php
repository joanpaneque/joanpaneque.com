<?php

namespace App\Providers;

use App\Services\GoogleCalendarService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleCalendarService::class, function () {
            return new GoogleCalendarService(
                config('services.google.client_id'),
                config('services.google.client_secret'),
                config('services.google.redirect_uri'),
                config('services.google.calendar_id')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
