<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Runtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Mobile\Facades\System;
use Throwable;

/**
 * Keeps the local SQLite mirror in step with the hosted API.
 *
 * Rows are keyed by the SERVER id on both sides — the server and the mobile
 * build both seed from {@see \App\Support\DemoWorld}, so the baseline ids line
 * up and every upsert can address a row by its server id directly.
 *
 * Everything here is best-effort: when the API is unreachable the methods
 * return quietly and screens keep rendering from the last synced state.
 */
class MessengerSync
{
    public function __construct(private readonly MessengerApi $api)
    {
    }

    public function api(): MessengerApi
    {
        return $this->api;
    }

    public function enabled(): bool
    {
        return $this->api->configured();
    }

    /** Re-probe the server on app foreground (a previously-down API may be back). */
    public function recheck(): void
    {
        $this->api->recheck();
    }

    /**
     * Full pull. Called from a screen's mount() — so it must stay bounded:
     * the one-shot online() probe caps a dead-server sync at ~3s instead of
     * ~30s of stacked request timeouts.
     */
    public function hydrate(): void
    {
        if (! $this->api->configured() || ! $this->api->online()) {
            return;
        }

        try {
            $this->api->flush();
            $this->ensureRegistered();
            $this->pullMe();
            $this->pullContacts();
            $this->pullConversations();
        } catch (Throwable $e) {
            Log::info('MessengerSync::hydrate skipped — '.$e->getMessage());
        }
    }

    /** Lightweight pulls for screens that don't need the whole world. */
    public function syncContacts(): void
    {
        if (! $this->api->configured() || ! $this->api->online()) {
            return;
        }

        try {
            $this->api->flush();
            $this->ensureRegistered();
            $this->pullContacts();
        } catch (Throwable $e) {
            Log::info('MessengerSync::syncContacts skipped — '.$e->getMessage());
        }
    }

    public function syncMe(): void
    {
        if (! $this->api->configured() || ! $this->api->online()) {
            return;
        }

        try {
            $this->api->flush();
            $this->ensureRegistered();
            $this->pullMe();
        } catch (Throwable $e) {
            Log::info('MessengerSync::syncMe skipped — '.$e->getMessage());
        }
    }

    public function ensureRegistered(): void
    {
        if ($this->api->authenticated()) {
            return;
        }

        $me = User::query()->oldest('id')->first();

        $this->api->registerDevice([
            'name' => $me?->name ?? 'You',
            'username' => $me?->username ?? 'you',
            'platform' => $this->platform(),
            'device_name' => 'SuperNative mobile',
        ]);
    }

    public function platform(): string
    {
        if (! Runtime::onDevice()) {
            return 'unknown';
        }

        try {
            return System::isAndroid() ? 'android' : (System::isIos() ? 'ios' : 'unknown');
        } catch (Throwable) {
            return 'unknown';
        }
    }

    // ── Pulls ───────────────────────────────────────────────────────────────

    public function pullMe(): void
    {
        $me = $this->api->me();

        if ($me) {
            $this->upsertUser($me);
        }
    }

    public function pullContacts(): void
    {
        foreach ($this->api->contacts() ?? [] as $contact) {
            $this->upsertUser($contact);
        }
    }

    public function pullConversations(): void
    {
        $rows = $this->api->conversations();

        if ($rows === null) {
            return;
        }

        foreach ($rows as $row) {
            $this->upsertConversation($row);
        }
    }

    public function pullThread(int $conversationId): void
    {
        $this->api->flush();
        $messages = $this->api->messages($conversationId);

        if ($messages === null) {
            return;
        }

        foreach ($messages as $message) {
            $this->upsertMessage($message);
        }
    }

    // ── Pushes ──────────────────────────────────────────────────────────────

    /** POST a message; upsert + return the server row, or null on failure. */
    public function pushMessage(int $conversationId, string $body): ?Message
    {
        $payload = $this->api->sendMessage($conversationId, $body, (string) Str::uuid());

        if (! $payload) {
            return null;
        }

        return $this->upsertMessage($payload);
    }

    public function openConversationWith(int $userId): ?int
    {
        $payload = $this->api->createConversation($userId);

        if (! $payload) {
            return null;
        }

        $this->upsertConversation($payload);

        return (int) $payload['id'];
    }

    public function pushSettings(array $settings): void
    {
        $this->api->updateSettings($settings);
    }

    public function pushPresence(bool $online): void
    {
        $this->api->presence($online);
    }

    public function pushRead(int $conversationId): void
    {
        $this->api->markRead($conversationId);
    }

    /** Live "Seen" watermark for a conversation, or null when unavailable. */
    public function othersReadThrough(int $conversationId): ?Carbon
    {
        $row = $this->api->conversation($conversationId);

        return empty($row['others_read_through']) ? null : Carbon::parse($row['others_read_through']);
    }

    // ── Upserts (keyed by server id) ────────────────────────────────────────

    protected function upsertUser(array $row): User
    {
        $user = User::find($row['id']) ?? new User;
        $user->id = $row['id'];
        $user->exists = User::whereKey($row['id'])->exists();

        $user->forceFill(array_filter([
            'name' => $row['name'] ?? $user->name ?? 'Unknown',
            'username' => $row['username'] ?? $user->username,
            'tagline' => $row['tagline'] ?? $user->tagline,
            'accent' => $row['accent'] ?? $user->accent ?? '#0A7CFF',
            'email' => $row['email'] ?? $user->email ?? Str::uuid().'@mirror.local',
            'password' => $user->password ?? Str::random(16),
        ], fn ($v) => $v !== null));

        $user->is_online = (bool) ($row['online'] ?? $user->is_online);

        if (array_key_exists('active_status_visible', $row)) {
            $user->active_status_visible = (bool) $row['active_status_visible'];
        }
        if (array_key_exists('read_receipts_enabled', $row)) {
            $user->read_receipts_enabled = (bool) $row['read_receipts_enabled'];
        }
        if (array_key_exists('theme_preference', $row)) {
            $user->theme_preference = $row['theme_preference'];
        }

        $user->save();

        return $user;
    }

    protected function upsertConversation(array $row): Conversation
    {
        $convo = Conversation::find($row['id']) ?? new Conversation;
        $convo->id = $row['id'];
        $convo->exists = Conversation::whereKey($row['id'])->exists();

        $convo->forceFill([
            'is_group' => (bool) ($row['is_group'] ?? false),
            'name' => $row['is_group'] ?? false ? ($row['title'] ?? null) : null,
            'last_message_at' => $row['last_message_at'] ?? null,
        ])->save();

        $participantIds = collect($row['participants'] ?? [])->pluck('id')->filter()->all();

        if ($participantIds) {
            $convo->participants()->syncWithoutDetaching($participantIds);
        }

        foreach ($row['messages'] ?? [] as $message) {
            $this->upsertMessage($message + ['conversation_id' => $convo->id]);
        }

        return $convo;
    }

    protected function upsertMessage(array $row): Message
    {
        $message = Message::find($row['id']) ?? new Message;
        $message->id = $row['id'];
        $message->exists = Message::whereKey($row['id'])->exists();

        $message->forceFill([
            'conversation_id' => $row['conversation_id'],
            'user_id' => $row['user_id'] ?? ($row['sender']['id'] ?? null),
            'body' => $row['body'] ?? '',
            'client_uuid' => $row['client_uuid'] ?? null,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['created_at'] ?? now(),
        ])->save();

        return $message;
    }
}
