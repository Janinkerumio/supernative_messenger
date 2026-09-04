<?php

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Events\PresenceChanged;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function actingUser(array $attributes = []): User
{
    return User::factory()->create($attributes + ['read_receipts_enabled' => true, 'active_status_visible' => true]);
}

function tokenFor(User $user): string
{
    return $user->createToken('test')->plainTextToken;
}

it('registers a device and issues a token', function () {
    $response = $this->postJson('/api/auth/register-device', [
        'name' => 'Jordan Rivera',
        'username' => 'jordan',
        'platform' => 'ios',
        'push_token' => 'apns-abc',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username']]);

    $this->assertDatabaseHas('users', ['username' => 'jordan']);
    $this->assertDatabaseHas('devices', ['push_token' => 'apns-abc', 'platform' => 'ios']);
});

it('lists conversations for the authenticated user only', function () {
    $me = actingUser();
    $friend = actingUser();
    $stranger = actingUser();

    $mine = Conversation::create(['is_group' => false]);
    $mine->participants()->attach([$me->id, $friend->id]);

    $theirs = Conversation::create(['is_group' => false]);
    $theirs->participants()->attach([$friend->id, $stranger->id]);

    $this->withToken(tokenFor($me))->getJson('/api/conversations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

it('sends a message and broadcasts it', function () {
    Event::fake([MessageSent::class]);

    $me = actingUser();
    $friend = actingUser();
    $convo = Conversation::create(['is_group' => false]);
    $convo->participants()->attach([$me->id, $friend->id]);

    $uuid = (string) Str::uuid();

    $this->withToken(tokenFor($me))
        ->postJson("/api/conversations/{$convo->id}/messages", ['body' => 'hi there', 'client_uuid' => $uuid])
        ->assertCreated()
        ->assertJsonPath('data.body', 'hi there');

    // Retried POST with the same client_uuid must not duplicate.
    $this->withToken(tokenFor($me))
        ->postJson("/api/conversations/{$convo->id}/messages", ['body' => 'hi there', 'client_uuid' => $uuid])
        ->assertOk();

    $this->assertDatabaseCount('messages', 1);
    Event::assertDispatched(MessageSent::class);
});

it('rejects messages from non-participants', function () {
    $outsider = actingUser();
    $convo = Conversation::create(['is_group' => false]);
    $convo->participants()->attach([actingUser()->id, actingUser()->id]);

    $this->withToken(tokenFor($outsider))
        ->postJson("/api/conversations/{$convo->id}/messages", ['body' => 'let me in'])
        ->assertForbidden();
});

it('honours the read-receipts privacy flag', function () {
    Event::fake([MessageRead::class]);

    $convo = Conversation::create(['is_group' => false]);
    $reader = actingUser(['read_receipts_enabled' => false]);
    $other = actingUser();
    $convo->participants()->attach([$reader->id, $other->id]);

    $this->withToken(tokenFor($reader))
        ->postJson("/api/conversations/{$convo->id}/read")
        ->assertOk()
        ->assertJsonPath('receipts', 'disabled');

    expect($convo->fresh()->lastReadAtFor($reader))->toBeNull();
    Event::assertNotDispatched(MessageRead::class);
});

it('records a read receipt when enabled', function () {
    Event::fake([MessageRead::class]);

    $convo = Conversation::create(['is_group' => false]);
    $reader = actingUser();
    $other = actingUser();
    $convo->participants()->attach([$reader->id, $other->id]);

    $this->withToken(tokenFor($reader))
        ->postJson("/api/conversations/{$convo->id}/read")
        ->assertOk();

    expect($convo->fresh()->load('participants')->lastReadAtFor($reader))->not->toBeNull();
    Event::assertDispatched(MessageRead::class);
});

it('honours the active-status privacy flag on presence', function () {
    Event::fake([PresenceChanged::class]);

    $hidden = actingUser(['active_status_visible' => false, 'is_online' => false]);

    $this->withToken(tokenFor($hidden))
        ->postJson('/api/presence', ['online' => true])
        ->assertOk()
        ->assertJsonPath('online', false)
        ->assertJsonPath('visible', false);

    expect($hidden->fresh()->is_online)->toBeFalse();
    Event::assertNotDispatched(PresenceChanged::class);
});

it('broadcasts presence when visible and changed', function () {
    Event::fake([PresenceChanged::class]);

    $user = actingUser(['is_online' => false]);
    $convo = Conversation::create(['is_group' => false]);
    $convo->participants()->attach([$user->id, actingUser()->id]);

    $this->withToken(tokenFor($user))
        ->postJson('/api/presence', ['online' => true])
        ->assertOk()
        ->assertJsonPath('online', true);

    expect($user->fresh()->is_online)->toBeTrue();
    Event::assertDispatched(PresenceChanged::class);
});

it('updates my settings', function () {
    $me = actingUser();

    $this->withToken(tokenFor($me))
        ->patchJson('/api/me/settings', [
            'read_receipts_enabled' => false,
            'theme_preference' => 'dark',
        ])
        ->assertOk()
        ->assertJsonPath('data.read_receipts_enabled', false)
        ->assertJsonPath('data.theme_preference', 'dark');
});

it('creates or reuses a direct conversation', function () {
    $me = actingUser();
    $friend = actingUser();

    $first = $this->withToken(tokenFor($me))
        ->postJson('/api/conversations', ['user_id' => $friend->id])
        ->assertSuccessful()->json('data.id');

    $second = $this->withToken(tokenFor($me))
        ->postJson('/api/conversations', ['user_id' => $friend->id])
        ->assertSuccessful()->json('data.id');

    expect($first)->toBe($second);
    $this->assertDatabaseCount('conversations', 1);
});
