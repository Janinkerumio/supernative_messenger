<?php

namespace App\Providers;

use App\Services\MessengerApi;
use App\Services\MessengerSync;
use App\Support\DemoWorld;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singletons so a screen's mount → render → navigationOptions cycle
        // reuses one memoized set of API reads instead of re-fetching.
        $this->app->singleton(MessengerApi::class);
        $this->app->singleton(MessengerSync::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The mobile runtime ships an empty (migrated) database, so populate
        // the demo world on first boot. Idempotent + self-guarding: it does
        // nothing once users exist, or before migrations have run.
        if (! $this->app->runningUnitTests()) {
            DemoWorld::ensure();
        }

        if(env('APP_ENV') === 'production')
        {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
