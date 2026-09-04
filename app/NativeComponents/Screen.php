<?php

namespace App\NativeComponents;

use App\Models\Conversation;
use App\Models\User;
use App\Services\MessengerApi;
use App\Services\MessengerSync;
use App\Support\Runtime;
use Native\Mobile\Facades\System;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\Edge\NativeComponent;

/**
 * Base screen for the SuperNative messenger.
 *
 * "me" is the first local user (Jordan) — the mobile build has no login;
 * it registers that identity with the API on first sync.
 */
abstract class Screen extends NativeComponent
{
    protected ?User $currentUser = null;

    protected function me(): User
    {
        return $this->currentUser ??= User::query()->oldest('id')->firstOrFail();
    }

    protected function api(): MessengerApi
    {
        return app(MessengerApi::class);
    }

    protected function sync(): MessengerSync
    {
        return app(MessengerSync::class);
    }

    /** True when the OS is in dark mode (drives the `dark:` blade classes too). */
    public function isDark(): bool
    {
        if (! Runtime::onDevice()) {
            return false;
        }

        try {
            return System::isDarkMode();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Re-render when the user flips light/dark while the app is open. */
    #[On(AppearanceChanged::class)]
    public function onAppearanceChanged(string $mode): void
    {
        // Body intentionally empty: the #[On] hook already triggers a re-render,
        // and the `dark:` classes resolve against the fresh system appearance.
    }

    /** Find the existing 1:1 conversation with $other, or create one locally. */
    protected function directConversationWith(User $other): Conversation
    {
        $me = $this->me();

        $existing = Conversation::query()
            ->where('is_group', false)
            ->whereHas('participants', fn ($q) => $q->where('users.id', $me->id))
            ->whereHas('participants', fn ($q) => $q->where('users.id', $other->id))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);

        if ($existing) {
            return $existing;
        }

        $conversation = Conversation::create(['is_group' => false]);
        $conversation->participants()->attach([$me->id, $other->id]);

        return $conversation;
    }

    /**
     * Resolve the conversation to open when messaging $other: prefer the API
     * (so both devices share the same conversation id), fall back to local.
     */
    protected function conversationIdWith(User $other): int
    {
        if ($this->sync()->enabled()) {
            $remoteId = $this->sync()->openConversationWith($other->id);

            if ($remoteId !== null) {
                return $remoteId;
            }
        }

        return $this->directConversationWith($other)->id;
    }
}
