<?php

namespace App\Providers;

use App\Services\MessengerApi;
use App\Services\MessengerSync;
use App\Services\PushManager;
use App\Support\DemoWorld;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use NativePHP\Vibe\Vibe;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons so a screen's mount → render → navigationOptions cycle
        // reuses one memoized set of API reads instead of re-fetching.
        $this->app->singleton(MessengerApi::class);
        $this->app->singleton(MessengerSync::class);
        $this->app->singleton(PushManager::class);
    }

    public function boot(): void
    {
        // The mobile runtime ships an empty (migrated) database — seed the demo
        // world on first boot (self-guarding; noop once accounts exist or in
        // production).
        if (! $this->app->runningUnitTests()) {
            DemoWorld::ensure();
        }

        // Vibe authorizes private/presence channels against the API's
        // /broadcasting/auth with the *current account's* bearer token,
        // resolved fresh so an account switch is picked up without a reconnect.
        if (class_exists(Vibe::class)) {
            app(Vibe::class)->resolveTokenUsing(fn () => app(MessengerApi::class)->token());
        }

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
