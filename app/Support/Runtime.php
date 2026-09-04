<?php

namespace App\Support;

/**
 * Is this process the real NativePHP mobile runtime?
 *
 * Off device (local dev, `php artisan serve`, the test suite) NativePHP
 * registers a Jump-bridge fallback for `nativephp_call()` that opens a TCP
 * socket per call and blocks for seconds when no bridge is listening. So any
 * `SecureStorage` / `System` / `PushNotifications` call must be gated on this.
 */
class Runtime
{
    public static function onDevice(): bool
    {
        return (bool) (config('nativephp-internal.running') || env('NATIVEPHP_RUNNING', false));
    }
}
