<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            \Native\Mobile\UI\NativeUIServiceProvider::class,
            \NativePHP\Vibe\VibeServiceProvider::class,
            // fatlum/nativephp-push — the native Swift/Kotlin FCM/APNs layer
            // behind core's PushNotifications facade. Vendored + patched for
            // nativephp/mobile 4.x under packages/nativephp-push.
            \Lumi\NativePush\PushServiceProvider::class,
        ];
    }
}