<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessengerApi;
use App\Services\MessengerSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.messenger.url' => 'https://api.test']);

    // A signed-in "jordan" account (server id 1) with a stored token.
    User::factory()->create(['id' => 1, 'name' => 'Jordan', 'username' => 'jordan']);
    Account::create([
        'username' => 'jordan', 'name' => 'Jordan', 'server_id' => 1,
        'is_current' => true, 'last_used_at' => now(),
    ]);
    Cache::forever('messenger.api_token.jordan', 'test-token');
});

function sync(): MessengerSync
{
    return app(MessengerSync::class);
}

it('reports configured + authenticated from the token', function () {
    expect(app(MessengerApi::class)->configured())->toBeTrue()
        ->and(app(MessengerApi::class)->authenticated())->toBeTrue();
});

it('hydrates contacts, conversations and messages by server id', function () {
    Http::fake([
        'api.test/up' => Http::response('OK'),
        'api.test/api/me' => Http::response(['data' => [
            'id' => 1, 'name' => 'Jordan', 'username' => 'jordan', 'accent' => '#0A7CFF',
        ]]),
        'api.test/api/contacts' => Http::response(['data' => [
            ['id' => 7, 'name' => 'Maya Chen', 'username' => 'maya', 'accent' => '#FF2D9B', 'online' => true],
            ['id' => 9, 'name' => 'Diego Santos', 'username' => 'diego', 'accent' => '#7C4DFF', 'online' => false],
        ]]),
        'api.test/api/conversations' => Http::response(['data' => [
            [
                'id' => 42,
                'is_group' => false,
                'title' => 'Maya Chen',
                'last_message_at' => now()->toIso8601String(),
                'participants' => [['id' => 1], ['id' => 7]],
                'messages' => [
                    ['id' => 500, 'conversation_id' => 42, 'user_id' => 7, 'body' => 'hi', 'created_at' => now()->toIso8601String()],
                ],
            ],
        ]]),
    ]);

    sync()->hydrate();

    expect(User::whereKey(7)->value('name'))->toBe('Maya Chen')
        ->and(User::whereKey(9)->exists())->toBeTrue();

    $convo = Conversation::find(42);
    expect($convo)->not->toBeNull()
        ->and($convo->participants()->pluck('users.id')->sort()->values()->all())->toBe([1, 7])
        ->and(Message::find(500)?->body)->toBe('hi');
});

it('pushes a message through the API and mirrors the server row', function () {
    User::factory()->create(['id' => 7, 'name' => 'Maya', 'username' => 'maya']);
    $convo = new Conversation(['is_group' => false]);
    $convo->id = 42;
    $convo->save();
    $convo->participants()->attach([1, 7]);

    Http::fake([
        'api.test/up' => Http::response('OK'),
        'api.test/api/conversations/42/messages' => Http::response(['data' => [
            'id' => 900, 'conversation_id' => 42, 'user_id' => 1, 'body' => 'sent!',
            'client_uuid' => (string) Str::uuid(), 'created_at' => now()->toIso8601String(),
        ]], 201),
    ]);

    $message = sync()->pushMessage(42, 'sent!');

    expect($message)->not->toBeNull()
        ->and($message->id)->toBe(900)
        ->and(Message::whereKey(900)->value('body'))->toBe('sent!');
});

it('returns null from pushMessage when the API is unreachable', function () {
    Http::fake(['api.test/*' => Http::response(null, 500)]);

    expect(sync()->pushMessage(42, 'nope'))->toBeNull();
});

it('bails out after a single probe when the server is unreachable', function () {
    // No stub for /up -> the reachability probe fails.
    Http::fake(['api.test/up' => Http::response('', 503)]);

    sync()->hydrate();

    // Exactly one request (the probe). No register-device, no pulls — this is
    // what stops a screen's mount() from stacking timeouts and freezing.
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/up'));
});

it('is inert when no API url is configured', function () {
    config(['services.messenger.url' => null]);
    Http::fake();

    sync()->hydrate();
    sync()->pushPresence(true);

    Http::assertNothingSent();
    expect(sync()->enabled())->toBeFalse();
});

it('registers the device, stores the token and binds the account', function () {
    Cache::forget('messenger.api_token.jordan');
    Account::current()->update(['server_id' => null]);

    Http::fake([
        'api.test/up' => Http::response('OK'),
        'api.test/api/auth/register-device' => Http::response([
            'token' => 'fresh-token',
            'user' => ['id' => 5, 'name' => 'Jordan', 'username' => 'jordan'],
        ]),
    ]);

    sync()->ensureRegistered();

    expect(app(MessengerApi::class)->token())->toBe('fresh-token')
        ->and(Account::current()->fresh()->server_id)->toBe(5);
    Http::assertSent(fn ($r) => $r->url() === 'https://api.test/api/auth/register-device'
        && $r['username'] === 'jordan');
});
