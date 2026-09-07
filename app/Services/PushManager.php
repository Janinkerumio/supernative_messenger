<?php

namespace App\Services;

use App\Support\Runtime;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Dialog;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\System;
use Throwable;

/**
 * FCM / APNs enrollment, token delivery to the API, the permission fallback
 * chain (enroll → open app settings → manual "open settings" dialog → an
 * on-screen hint), and the notification *priming* state — a one-time
 * pre-permission explainer shown before the OS prompt (see shouldPrime()).
 *
 * Every native call is guarded so dev / tests (no bridge) are inert.
 */
class PushManager
{
    private const ASKED_KEY = 'push.enroll_requested';

    private const SENT_TOKEN_KEY = 'push.last_sent_token';

    /** Persisted priming decision: 'enrolled' | 'dismissed' | null. */
    private const PRIME_KEY = 'push.prime_decision';

    /**
     * Once-per-process guard so the priming sheet is offered at most once per
     * launch (mirrors textbitz's module-level `offeredThisSession`). The
     * PushManager is a singleton, so this survives poll ticks / re-renders.
     */
    private bool $primeOfferedThisRun = false;

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
     *
     * The token is delivered back via the `TokenGenerated` native event, picked
     * up by the `#[On(TokenGenerated::class)]` handler on whichever screen is
     * mounted (Settings / ConvoList) and, as a backstop, by syncToken() on the
     * poll tick. We deliberately do NOT register a fluent
     * `->tokenGenerated($cb)` callback here: that path invokes the callback with
     * `call_user_func()` and no object context, so a bare method name isn't a
     * valid callable and fatals in NativeComponent::fireNativeCallback().
     */
    public function enroll(): bool
    {
        if (! Runtime::onDevice() || ! function_exists('nativephp_call')) {
            return false;
        }

        try {
            // Fire the bridge call now (rather than leaving it to __destruct)
            // so any failure surfaces here and can't escape during GC.
            PushNotifications::enroll()->enroll();

            Cache::forever(self::ASKED_KEY, true);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    // ── Priming ───────────────────────────────────────────────────────────
    //
    // A pre-permission explainer shown *once*, before the OS prompt, only
    // while the decision is still open. Ported from textbitz_gate's
    // usePushPriming.js: session-once + a persisted decision + the same
    // enroll → app settings → dialog fallback chain (see requestPermissionFlow).

    /**
     * True at most once per launch: on device, not offered yet this run, no
     * stored decision, permission still `not_determined`. Sets the once-guard
     * as a side effect (like `shouldOfferNotificationOptIn()`).
     */
    public function shouldPrime(): bool
    {
        if (! Runtime::onDevice() || $this->primeOfferedThisRun) {
            return false;
        }

        if (Cache::has(self::PRIME_KEY) || Cache::get(self::ASKED_KEY)) {
            return false;
        }

        if ($this->permission() !== 'not_determined') {
            return false;
        }

        $this->primeOfferedThisRun = true;

        return true;
    }

    /** 'enrolled' (user tapped through), 'dismissed' ("Not now"), or null. */
    public function primeDecision(): ?string
    {
        return Cache::get(self::PRIME_KEY);
    }

    /** Record that the user opted in from the primer — never prime again. */
    public function markPrimeAccepted(): void
    {
        Cache::forever(self::PRIME_KEY, 'enrolled');
    }

    /** Record an explicit "Not now" — never prime again on this account. */
    public function dismissPrime(): void
    {
        Cache::forever(self::PRIME_KEY, 'dismissed');
    }

    /**
     * The permission fallback chain, shared by the Settings toggle and the
     * priming sheet: OS prompt → open app settings → manual dialog → hint.
     *
     * The token (from enroll) and the dialog button press are both delivered
     * back through the screen's `#[On(TokenGenerated::class)]` /
     * `#[On(ButtonPressed::class)]` handlers — never a fluent string callback,
     * which fatals when NativeComponent invokes it without object context.
     *
     * @return 'granted'|'enrolling'|'settings'|'dialog'|'hint'
     */
    public function requestPermissionFlow(): string
    {
        if ($this->granted()) {
            return 'granted';
        }

        if (! $this->blocked() && $this->enroll()) {
            return 'enrolling';
        }

        if ($this->openAppSettings()) {
            return 'settings';
        }

        if ($this->manualDialog()) {
            return 'dialog';
        }

        return 'hint';
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
     * The button press comes back via the screen's `#[On(ButtonPressed::class)]`
     * handler (`onNotificationDialog`), so nothing is wired here.
     */
    public function manualDialog(): bool
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
                ->show();

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
        Cache::forget(self::PRIME_KEY);
        $this->primeOfferedThisRun = false;
    }
}
