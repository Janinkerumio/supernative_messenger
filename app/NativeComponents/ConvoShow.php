<?php

namespace App\NativeComponents;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;

class ConvoShow extends Screen
{
    /** Hide the tab bar — this is a pushed detail screen. */
    protected bool $hidesTabBar = true;

    public int $conversationId = 0;

    public string $draft = '';

    public bool $sendFailed = false;

    /** ISO timestamp of the last thread pull, to throttle re-render pulls. */
    public ?string $syncedAt = null;

    protected ?Conversation $conversation = null;

    protected ?Carbon $seenThrough = null;

    public function mount(): void
    {
        if ($this->requireOnboarding()) {
            return;
        }

        // Local only — the thread renders instantly from the mirror.
        $this->conversationId = (int) $this->param('conversation', $this->conversationId);

        // Live delivery over the websocket; the poll below is the fallback for
        // when the socket isn't connected (offline, backgrounded, cold start).
        if ($this->conversationId > 0) {
            $this->watchConversation($this->conversationId, 'onRealtime');
        }
    }

    /** Websocket event on this thread's channel (or a reconnect → $event null). */
    public function onRealtime(mixed $event = null): void
    {
        $this->pullThread(force: true);
        $this->markRead();
    }

    /**
     * Fallback + initial load. First fires ~5s in; mostly a no-op once the
     * websocket is delivering, the safety net when it isn't.
     */
    #[Poll(5000)]
    public function refresh(): void
    {
        if ($this->conversationId <= 0) {
            return;
        }

        $this->pullThread(force: true);
        $this->markRead();
    }

    protected function pullThread(bool $force = false): void
    {
        if (! $this->sync()->enabled()) {
            return;
        }

        // Skip a pull on rapid keystroke re-renders.
        if (! $force && $this->syncedAt && now()->diffInSeconds(Carbon::parse($this->syncedAt)) < 2) {
            return;
        }

        $this->sync()->pullThread($this->conversationId);
        $this->syncedAt = now()->toIso8601String();
        $this->conversation = null; // reload relations next access
        $this->seenThrough = null;  // recompute the "Seen" watermark
    }

    protected function markRead(): void
    {
        if ($this->sync()->enabled() && $this->me()->read_receipts_enabled) {
            $this->sync()->pushRead($this->conversationId);
        }
    }

    protected function conversation(): Conversation
    {
        return $this->conversation ??= Conversation::query()
            ->with('participants')
            ->findOrFail($this->conversationId);
    }

    public function navigationOptions(): ?NavBarOptions
    {
        if ($this->onboardingRedirect || $this->conversationId <= 0) {
            return null;
        }

        $me = $this->me();
        $convo = $this->conversation();

        return NavBarOptions::make()
            ->title($convo->titleFor($me))
            ->subtitle($convo->is_group
                ? $convo->participants->count().' members'
                : ($convo->counterpart($me)?->presenceLabel($me) ?? ''))
            ->back()
            ->displayMode('inline');
    }

    public function send(): void
    {
        $body = trim($this->draft);

        if ($body === '') {
            return;
        }

        $convo = $this->conversation();

        if ($this->sync()->enabled()) {
            $message = $this->sync()->pushMessage($convo->id, $body);

            if ($message === null) {
                // Offline / server error — keep the draft so nothing is lost.
                $this->sendFailed = true;

                return;
            }
        } else {
            Message::create([
                'conversation_id' => $convo->id,
                'user_id' => $this->me()->id,
                'body' => $body,
            ]);
            $convo->update(['last_message_at' => now()]);
        }

        $this->sendFailed = false;
        $this->draft = '';
        $this->conversation = null;
    }

    public function render(): View|Element
    {
        if ($this->onboardingRedirect || $this->conversationId <= 0) {
            return $this->blankScreen();
        }

        $me = $this->me();
        $convo = $this->conversation();

        // "Seen" watermark — only when both parties allow read receipts.
        if ($this->sync()->enabled() && $me->read_receipts_enabled) {
            $this->seenThrough ??= $this->sync()->othersReadThrough($convo->id);
        }

        $messages = $convo->messages()->with('user')->oldest()->get();

        $rows = [];
        $lastSender = null;
        $lastMineId = null;

        foreach ($messages as $message) {
            /** @var Message $message */
            $mine = $message->mine($me);
            $rows[] = [
                'id' => $message->id,
                'body' => $message->body,
                'mine' => $mine,
                'time' => $message->timeLabel(),
                'sender' => $convo->is_group && ! $mine ? $message->user->name : null,
                'grouped' => $lastSender === $message->user_id,
                'accent' => $message->user->accent ?? '#0A7CFF',
                'initials' => $message->user->initials(),
                'seen' => false,
            ];
            if ($mine) {
                $lastMineId = array_key_last($rows);
            }
            $lastSender = $message->user_id;
        }

        // Tag the last of my messages as "Seen" if the other side has read past it.
        if ($lastMineId !== null && $this->seenThrough) {
            $lastMine = $messages->firstWhere('id', $rows[$lastMineId]['id']);
            $rows[$lastMineId]['seen'] = $lastMine && $this->seenThrough->gte($lastMine->created_at);
        }

        return view('native.convo-show', [
            'rows' => $rows,
            'title' => $convo->titleFor($me),
            'isGroup' => $convo->is_group,
            'accent' => $convo->is_group ? '#8E8E93' : ($convo->counterpart($me)?->accent ?? '#0A7CFF'),
            'initials' => $convo->is_group ? '#' : ($convo->counterpart($me)?->initials() ?? '?'),
            'presence' => $convo->is_group ? null : $convo->counterpart($me)?->presenceLabel($me),
            'canSend' => trim($this->draft) !== '',
            'sendFailed' => $this->sendFailed,
        ]);
    }
}
