<?php

namespace App\Providers;

use App\Services\MessengerApi;
use App\Services\MessengerSync;
use App\Services\PushManager;
use App\Support\DemoWorld;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Events\PushNotification\TokenGenerated;
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

        // fatlum/nativephp-push dispatches TokenGenerated on every FCM token
        // (initial enroll AND a background refresh via onNewToken → the
        // ephemeral runtime). Register it globally, not just on a screen, so a
        // refresh while no #[On(TokenGenerated)] screen is mounted still lands.
        Event::listen(TokenGenerated::class, function (TokenGenerated $event): void {
            app(PushManager::class)->sendToken($event->token);
        });

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }
}
