<?php

namespace App\NativeComponents;

use App\Models\Account;
use App\Services\PushManager;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

class Settings extends Screen
{
    public bool $activeStatus = true;

    public bool $readReceipts = true;

    /** system | light | dark */
    public string $theme = 'system';

    /** granted | denied | not_determined | provisional | null(off-device) */
    public ?string $pushPermission = null;

    public bool $pushEnrolling = false;

    public bool $pushFallbackTried = false;

    /** Inline hint shown when every native fallback is unavailable. */
    public ?string $pushHint = null;

    public function mount(): void
    {
        if ($this->requireOnboarding()) {
            return;
        }

        $me = $this->me();
        $this->activeStatus = (bool) $me->active_status_visible;
        $this->readReceipts = (bool) $me->read_receipts_enabled;
        $this->theme = $me->theme_preference ?? 'system';

        $this->pushPermission = $this->push()->permission();
    }

    #[Poll(8000)]
    public function live(): void
    {
        if (! $this->hasIdentity()) {
            return;
        }

        $this->sync()->syncMe();
        $this->push()->syncToken();
        $this->pushPermission = $this->push()->permission();

        // Enrolled, but the user denied at the prompt — escalate once.
        if ($this->pushEnrolling && $this->pushPermission === 'denied' && ! $this->pushFallbackTried) {
            $this->pushFallbackTried = true;
            $this->pushEnrolling = false;
            $this->push()->openAppSettings() || $this->push()->manualDialog('onNotificationDialog');
        }

        $me = $this->me()->refresh();
        $this->activeStatus = (bool) $me->active_status_visible;
        $this->readReceipts = (bool) $me->read_receipts_enabled;
        $this->theme = $me->theme_preference ?? 'system';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->title('Settings')->displayMode('large');
    }

    protected function push(): PushManager
    {
        return app(PushManager::class);
    }

    // ── Accounts ─────────────────────────────────────────────────────────────

    public function switchAccount(int $accountId): void
    {
        $target = Account::find($accountId);

        if (! $target || $target->is_current) {
            return;
        }

        $this->sync()->switchTo($target);
        $this->push()->reset();
        $this->forgetMe();
        $this->replace('/');
    }

    public function addAccount(): void
    {
        $this->navigate('/welcome');
    }

    public function signOut(): void
    {
        $next = $this->sync()->signOut();
        $this->push()->reset();
        $this->forgetMe();

        $this->replace($next ? '/' : '/welcome');
    }

    // ── Privacy feature flags ──────────────────────────────────────────────

    public function toggleActiveStatus(bool $value): void
    {
        $this->activeStatus = $value;
        $this->me()->forceFill(['active_status_visible' => $value, 'is_online' => $value && $this->me()->is_online])->save();

        $this->sync()->pushSettings(['active_status_visible' => $value]);

        if (! $value) {
            $this->sync()->pushPresence(false);
        }
    }

    public function toggleReadReceipts(bool $value): void
    {
        $this->readReceipts = $value;
        $this->me()->forceFill(['read_receipts_enabled' => $value])->save();

        $this->sync()->pushSettings(['read_receipts_enabled' => $value]);
    }

    // ── Appearance ─────────────────────────────────────────────────────────

    public function setTheme(string $mode): void
    {
        if (! in_array($mode, ['system', 'light', 'dark'], true)) {
            return;
        }

        $this->theme = $mode;
        $this->me()->forceFill(['theme_preference' => $mode])->save();
        $this->sync()->pushSettings(['theme_preference' => $mode]);
    }

    // ── Push notifications — enroll → app settings → dialog → hint ──────────

    public function enablePush(): void
    {
        $this->pushHint = null;
        $this->pushFallbackTried = false;

        $this->pushPermission = $this->push()->permission();

        if ($this->push()->granted()) {
            return;
        }

        // 1) OS permission prompt / enrollment.
        if (! $this->push()->blocked() && $this->push()->enroll('onPushToken')) {
            $this->pushEnrolling = true;
            $this->pushPermission = $this->push()->permission();

            return;
        }

        // 2) Blocked, or enroll couldn't dispatch → open the app's settings.
        if ($this->push()->openAppSettings()) {
            $this->pushHint = 'Allow notifications for SuperNative in the settings screen that just opened.';

            return;
        }

        // 3) Can't open settings → a native dialog asking them to do it manually.
        if ($this->push()->manualDialog('onNotificationDialog')) {
            return;
        }

        // 4) Last resort — an on-screen hint.
        $this->pushHint = 'Notifications are off. Open your device Settings → SuperNative → Notifications to turn them on.';
    }

    #[On(ButtonPressed::class)]
    public function onNotificationDialog(int $index): void
    {
        if ($index === 0) {   // "Open Settings"
            $this->push()->openAppSettings();
        }
    }

    #[On(TokenGenerated::class)]
    public function onPushToken(string $token): void
    {
        $this->pushEnrolling = false;
        $this->push()->sendToken($token);
        $this->pushPermission = $this->push()->permission();
    }

    #[Poll(3000)]
    public function pollPush(): void
    {
        $this->push()->syncToken();
        $this->pushPermission = $this->push()->permission();
    }

    public function render(): View|Element
    {
        if ($this->onboardingRedirect) {
            return $this->blankScreen();
        }

        $me = $this->me();
        $current = Account::current();

        return view('native.settings', [
            'name' => $me->name,
            'handle' => '@'.($me->username ?? str($me->name)->slug()),
            'tagline' => $me->tagline,
            'initials' => $me->initials(),
            'accent' => $me->accent,

            'otherAccounts' => Account::query()
                ->when($current, fn ($q) => $q->whereKeyNot($current->id))
                ->orderByDesc('last_used_at')
                ->get()
                ->map(fn (Account $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'handle' => '@'.$a->username,
                    'initials' => $this->accountInitials($a->name),
                    'accent' => $a->accent,
                ])
                ->all(),

            'activeStatus' => $this->activeStatus,
            'readReceipts' => $this->readReceipts,
            'theme' => $this->theme,
            'appearanceNow' => $this->isDark() ? 'Dark' : 'Light',
            'pushState' => $this->pushStateLabel(),
            'pushOn' => $this->pushPermission === 'granted' || $this->pushPermission === 'provisional',
            'pushBlocked' => $this->pushPermission === 'denied',
            'pushHint' => $this->pushHint,
            'apiLinked' => $this->api()->authenticated(),
        ]);
    }

    protected function accountInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $a = \Illuminate\Support\Str::substr($parts[0] ?? '', 0, 1);
        $b = count($parts) > 1 ? \Illuminate\Support\Str::substr(end($parts), 0, 1) : '';

        return \Illuminate\Support\Str::upper($a.$b) ?: \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($name, 0, 2));
    }

    protected function pushStateLabel(): string
    {
        return match ($this->pushPermission) {
            'granted', 'provisional' => 'On',
            'denied' => 'Blocked in system settings',
            'not_determined', null => $this->pushEnrolling ? 'Requesting…' : 'Off',
            default => ucfirst((string) $this->pushPermission),
        };
    }
}
