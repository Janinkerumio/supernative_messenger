<?php

namespace App\Services;

use App\Support\Runtime;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Dialog;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\System;
use Throwable;

/**
 * FCM / APNs enrollment, token delivery to the API, and the permission
 * fallback chain: enroll → open app settings → manual "open settings" dialog
 * → an on-screen hint.
 *
 * Every native call is guarded so dev / tests (no bridge) are inert.
 */
class PushManager
{
    private const ASKED_KEY = 'push.enroll_requested';

    private const SENT_TOKEN_KEY = 'push.last_sent_token';

    public function __construct(private readonly MessengerSync $sync)
    {
    }

    /** granted | denied | not_determined | provisional | null (off device). */
    public function permission(): ?string
    {
        if (! Runtime::onDevice()) {
            return null;
        }

        try {
            return PushNotifications::checkPermission();
        } catch (Throwable) {
            return null;
        }
    }

    public function granted(): bool
    {
        return in_array($this->permission(), ['granted', 'provisional'], true);
    }

    public function blocked(): bool
    {
        return $this->permission() === 'denied';
    }

    /**
     * Ask for permission + enrol for a push token. Returns true if the request
     * was dispatched (not whether the user granted — that's async).
     */
    public function enroll(?string $tokenCallback = null): bool
    {
        if (! Runtime::onDevice()) {
            return false;
        }

        try {
            $pending = PushNotifications::enroll();

            if ($tokenCallback) {
                $pending->tokenGenerated($tokenCallback);
            }

            Cache::forever(self::ASKED_KEY, true);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Prompt for notifications once, quietly, right after onboarding. */
    public function autoRequestOnce(): void
    {
        if (! Runtime::onDevice() || Cache::get(self::ASKED_KEY)) {
            return;
        }

        if ($this->permission() === 'not_determined') {
            $this->enroll();
        }
    }

    public function openAppSettings(): bool
    {
        if (! Runtime::onDevice()) {
            return false;
        }

        try {
            app(System::class)->appSettings();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Last-resort native dialog telling the user to open settings by hand.
     * $handler is a component method name for the ButtonPressed event.
     */
    public function manualDialog(string $handler): bool
    {
        if (! Runtime::onDevice()) {
            return false;
        }

        try {
            app(Dialog::class)
                ->alert(
                    'Turn on notifications',
                    "Open your device Settings \u{2192} SuperNative \u{2192} Notifications and allow notifications, then come back.",
                    ['Open Settings', 'Not now'],
                )
                ->buttonPressed($handler);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * If the device has a push token we haven't registered with the API yet,
     * send it. Cheap to call from a poll tick.
     */
    public function syncToken(): void
    {
        if (! Runtime::onDevice() || ! $this->sync->api()->authenticated()) {
            return;
        }

        try {
            $token = PushNotifications::getToken();
        } catch (Throwable) {
            return;
        }

        if ($token && $token !== Cache::get(self::SENT_TOKEN_KEY)) {
            $this->sync->api()->registerPushToken($token, $this->sync->platform());
            Cache::forever(self::SENT_TOKEN_KEY, $token);
        }
    }

    public function sendToken(string $token): void
    {
        if ($token === '' || $token === Cache::get(self::SENT_TOKEN_KEY) || ! $this->sync->api()->authenticated()) {
            return;
        }

        $this->sync->api()->registerPushToken($token, $this->sync->platform());
        Cache::forever(self::SENT_TOKEN_KEY, $token);
    }

    /** Forget the "already asked" + "already sent" markers (e.g. on sign-out). */
    public function reset(): void
    {
        Cache::forget(self::ASKED_KEY);
        Cache::forget(self::SENT_TOKEN_KEY);
    }
}
