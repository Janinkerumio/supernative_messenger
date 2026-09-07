<?php

namespace App\NativeComponents;

use App\Support\Runtime;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;
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

    protected ?string $lastSentPushToken = null;

    public function mount(): void
    {
        if ($this->requireOnboarding()) {
            return;
        }

        // Local only — toggle state comes from the local `me` row.
        $me = $this->me();

        $this->activeStatus = (bool) $me->active_status_visible;
        $this->readReceipts = (bool) $me->read_receipts_enabled;
        $this->theme = $me->theme_preference ?? 'system';

        $this->refreshPushState();
    }

    #[Poll(8000)]
    public function live(): void
    {
        if (! $this->hasIdentity()) {
            return;
        }

        $this->sync()->syncMe();

        $me = $this->me()->refresh();
        $this->activeStatus = (bool) $me->active_status_visible;
        $this->readReceipts = (bool) $me->read_receipts_enabled;
        $this->theme = $me->theme_preference ?? 'system';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()->title('Settings')->displayMode('large');
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

    // ── Push notifications ────────────────────────────────────────────────

    public function enablePush(): void
    {
        if (! Runtime::onDevice()) {
            return;
        }

        $this->pushEnrolling = true;

        // Requests the OS permission prompt and enrolls for FCM/APNs. The token
        // arrives asynchronously — captured by onPushToken() or the poll below.
        PushNotifications::enroll()->tokenGenerated('onPushToken');

        $this->refreshPushState();
    }

    public function openSystemSettings(): void
    {
        if (Runtime::onDevice()) {
            app(\Native\Mobile\System::class)->appSettings();
        }
    }

    #[On(TokenGenerated::class)]
    public function onPushToken(string $token): void
    {
        $this->pushEnrolling = false;
        $this->sendPushToken($token);
        $this->refreshPushState();
    }

    /** Fallback capture: poll for the token after enrollment. */
    #[Poll(3000)]
    public function pollPush(): void
    {
        if (! Runtime::onDevice() || $this->pushPermission === null) {
            return;
        }

        $token = PushNotifications::getToken();

        if ($token) {
            $this->sendPushToken($token);
        }

        $this->refreshPushState();
    }

    protected function sendPushToken(string $token): void
    {
        if ($token === $this->lastSentPushToken || ! $this->sync()->enabled()) {
            return;
        }

        $this->sync()->api()->registerPushToken($token, $this->sync()->platform());
        $this->lastSentPushToken = $token;
    }

    protected function refreshPushState(): void
    {
        $this->pushPermission = Runtime::onDevice()
            ? PushNotifications::checkPermission()
            : null;
    }

    public function render(): View|Element
    {
        if ($this->onboardingRedirect) {
            return $this->blankScreen();
        }

        $me = $this->me();

        return view('native.settings', [
            'name' => $me->name,
            'handle' => '@'.($me->username ?? str($me->name)->slug()),
            'tagline' => $me->tagline,
            'initials' => $me->initials(),
            'accent' => $me->accent,
            'activeStatus' => $this->activeStatus,
            'readReceipts' => $this->readReceipts,
            'theme' => $this->theme,
            'appearanceNow' => $this->isDark() ? 'Dark' : 'Light',
            'pushState' => $this->pushStateLabel(),
            'pushOn' => $this->pushPermission === 'granted',
            'pushBlocked' => $this->pushPermission === 'denied',
            'apiLinked' => $this->api()->authenticated(),
        ]);
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
