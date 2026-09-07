<?php

namespace App\NativeComponents;

use App\Models\Conversation;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Events\PushNotification\TokenGenerated;

class ConvoList extends Screen
{
    public string $query = '';

    /** Drives the pre-permission notification explainer sheet. */
    public bool $showPushPrimer = false;

    private bool $presenceSent = false;

    // mount() does NO network — the first paint renders from the local mirror
    // instantly; the API sync runs on the poll tick below so a slow/unreachable
    // server can never freeze app launch.
    public function mount(): void
    {
        if ($this->requireOnboarding()) {
            return;
        }

        // One always-on subscription (private-users.{id}) delivers every
        // message across every thread — new conversations included. Handled by
        // Screen::onInbox() → pullConversations(). #[Poll] below is the
        // fallback for when the socket isn't connected.
        $this->watchInbox();
    }

    public function onResume(): void
    {
        // Coming back to the foreground — a previously-unreachable API may be
        // back, so allow one fresh reachability probe.
        $this->sync()->recheck();
        $this->presenceSent = false;
    }

    /** Background refresh: pull latest state, report presence, wire push. */
    #[Poll(2000)]
    public function live(): void
    {
        if (! $this->hasIdentity()) {
            return;
        }

        $this->sync()->hydrate();
        $this->push()->syncToken();

        if (! $this->presenceSent && $this->sync()->enabled() && $this->me()->active_status_visible) {
            $this->sync()->pushPresence(true);
            $this->presenceSent = true;
        }
    }

    // ── Notification priming ─────────────────────────────────────────────
    //
    // The Edge equivalent of textbitz_gate's NotificationOptInModal: a
    // pre-permission explainer sheet, offered once per launch while the
    // decision is still open, a short beat after the list has settled.

    #[Poll(2500)]
    public function pushPrimerTick(): void
    {
        if (! $this->showPushPrimer && $this->hasIdentity() && $this->push()->shouldPrime()) {
            $this->showPushPrimer = true;
        }
    }

    /** "Turn on notifications" — run the same chain as the Settings toggle. */
    public function enablePushFromPrimer(): void
    {
        $this->showPushPrimer = false;
        $this->push()->markPrimeAccepted();
        $this->push()->requestPermissionFlow();
    }

    /** "Not now" — record the decision so the sheet never returns. */
    public function dismissPushPrimer(): void
    {
        $this->showPushPrimer = false;
        $this->push()->dismissPrime();
    }

    /**
     * Backdrop tap / drag-down: just hide. No decision is recorded, so the
     * explainer can appear again on a later launch (matches textbitz).
     */
    public function onPrimerDismissed(): void
    {
        $this->showPushPrimer = false;
    }

    #[On(TokenGenerated::class)]
    public function onPushToken(string $token): void
    {
        $this->push()->sendToken($token);
    }

    #[On(ButtonPressed::class)]
    public function onNotificationDialog(int $index): void
    {
        if ($index === 0) {   // "Open Settings"
            $this->push()->openAppSettings();
        }
    }

    public function navigationOptions(): ?NavBarOptions
    {
        return NavBarOptions::make()
            ->title('Chats')
            ->displayMode('large')
            ->searchBar(placeholder: 'Search Messenger', onQuery: 'search', debounceMs: 150)
            ->action(
                NavAction::make('compose')
                    ->icon('square.and.pencil')
                    ->a11yLabel('New message')
                    ->press('compose')
            );
    }

    public function search(string $text): void
    {
        $this->query = trim($text);
    }

    public function compose(): void
    {
        $this->navigate('/new-chat');
    }

    public function open(int $id): void
    {
        $this->navigate("/chats/{$id}");
    }

    public function render(): View|Element
    {
        if ($this->onboardingRedirect) {
            return $this->blankScreen();
        }

        $me = $this->me();

        $rows = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('users.id', $me->id))
            ->with(['participants', 'messages' => fn ($q) => $q->latest()->limit(1)])
            ->orderByDesc('last_message_at')
            ->get()
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->titleFor($me),
                'preview' => $c->previewFor($me),
                'time' => optional($c->last_message_at)->diffForHumans(short: true) ?? '',
                'initials' => $c->is_group
                    ? '#'
                    : ($c->counterpart($me)?->initials() ?? '?'),
                'accent' => $c->is_group ? '#8E8E93' : ($c->counterpart($me)?->accent ?? '#0A7CFF'),
                'online' => ! $c->is_group && (bool) $c->counterpart($me)?->showsOnlineDotTo($me),
                'last_from_me' => (bool) optional($c->messages->last())->mine($me),
            ])
            ->when($this->query !== '', fn ($rows) => $rows->filter(
                fn ($r) => str_contains(mb_strtolower($r['title'].' '.$r['preview']), mb_strtolower($this->query))
            ))
            ->values()
            ->all();

        return view('native.convo-list', [
            'rows' => $rows,
            'empty' => $rows === [],
            'showPushPrimer' => $this->showPushPrimer,
        ]);
    }
}
