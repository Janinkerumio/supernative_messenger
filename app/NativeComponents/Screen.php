<?php

namespace App\NativeComponents;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\User;
use App\Services\MessengerApi;
use App\Services\MessengerSync;
use App\Services\PushManager;
use App\Support\Runtime;
use Illuminate\Support\Str;
use Native\Mobile\Facades\System;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\System\AppearanceChanged;
use Native\Mobile\Edge\Element;
use Native\Mobile\Edge\Elements\Column;
use Native\Mobile\Edge\NativeComponent;
use NativePHP\Vibe\Facades\Vibe;
use Throwable;

/**
 * Base screen for the SuperNative messenger.
 *
 * The active identity is the `is_current` {@see Account}. Its mirrored `User`
 * row (keyed by server id) is resolved by me(); before the first sync a
 * provisional local row stands in so screens can still query by id.
 */
abstract class Screen extends NativeComponent
{
    protected ?User $currentUser = null;

    protected bool $onboardingRedirect = false;

    protected function me(): User
    {
        if ($this->currentUser) {
            return $this->currentUser;
        }

        $account = Account::current();

        if ($account?->server_id && ($user = User::find($account->server_id))) {
            return $this->currentUser = $user;
        }

        if ($account) {
            // Not registered yet (first launch offline, or mid-onboarding).
            // A persisted stand-in keeps `$me->id` queryable; ensureRegistered()
            // replaces it with the server-id row on the first successful sync.
            return $this->currentUser = User::firstOrCreate(
                ['username' => $account->username],
                [
                    'name' => $account->name,
                    'accent' => $account->accent,
                    'email' => $account->username.'@local.supernative',
                    'password' => Str::random(32),
                    'is_online' => true,
                ],
            );
        }

        return $this->currentUser = User::query()->oldest('id')->firstOrFail();
    }

    protected function hasIdentity(): bool
    {
        return Account::query()->exists();
    }

    protected function forgetMe(): void
    {
        $this->currentUser = null;
    }

    /**
     * Bounce to the onboarding screen when there's no local identity yet.
     * Call first thing in mount(); pair with the render() guard below.
     */
    protected function requireOnboarding(): bool
    {
        if ($this->hasIdentity()) {
            return false;
        }

        $this->onboardingRedirect = true;
        $this->replace('/welcome');

        return true;
    }

    /** Placeholder tree published for the single frame before the redirect lands. */
    protected function blankScreen(): Element
    {
        return Column::make()->fill();
    }

    protected function api(): MessengerApi
    {
        return app(MessengerApi::class);
    }

    protected function sync(): MessengerSync
    {
        return app(MessengerSync::class);
    }

    protected function push(): PushManager
    {
        return app(PushManager::class);
    }

    /**
     * Subscribe this screen to a conversation's realtime channel. `$onEvent`
     * is a method on the screen invoked (with the raw event object) for each
     * `message.sent` / `message.read` / `presence.changed`. Auto-unsubscribes
     * when the screen unmounts. Inert off-device or without an API configured.
     */
    protected function watchConversation(int $conversationId, string $onEvent): void
    {
        if (! Runtime::onDevice() || ! $this->api()->configured()) {
            return;
        }

        try {
            Vibe::private('conversations.'.$conversationId)
                ->on('message.sent', fn ($event) => $this->{$onEvent}($event))
                ->on('message.read', fn ($event) => $this->{$onEvent}($event))
                ->on('presence.changed', fn ($event) => $this->{$onEvent}($event))
                ->onReconnect(fn () => $this->{$onEvent}(null));
        } catch (Throwable $e) {
            report($e);
        }
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
