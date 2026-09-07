<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\NativeComponents\Settings;
use App\Services\MessengerApi;
use App\Services\MessengerSync;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Testing\Native;

function account(string $username, int $serverId, bool $current = false): Account
{
    $user = User::factory()->create(['id' => $serverId, 'name' => ucfirst($username), 'username' => $username]);

    return Account::create([
        'username' => $username,
        'name' => $user->name,
        'accent' => '#0A7CFF',
        'server_id' => $serverId,
        'is_current' => $current,
        'last_used_at' => now(),
    ]);
}

it('stores the API token per account', function () {
    config(['services.messenger.url' => 'https://api.test']);

    $jordan = account('jordan', 1, current: true);
    account('maya', 2);

    Cache::forever('messenger.api_token.jordan', 'jordan-token');
    Cache::forever('messenger.api_token.maya', 'maya-token');

    expect(app(MessengerApi::class)->token())->toBe('jordan-token');

    Account::where('username', 'maya')->first()->makeCurrent();

    expect(app(MessengerApi::class)->token())->toBe('maya-token');
});

it('wipes the conversation mirror but keeps every account identity', function () {
    $jordan = account('jordan', 1, current: true);
    $maya = account('maya', 2);
    $diego = User::factory()->create(['id' => 9, 'username' => 'diego']);   // a contact, not an account

    $convo = Conversation::create(['is_group' => false]);
    $convo->id = 50;
    $convo->save();
    $convo->participants()->attach([1, 9]);
    Message::create(['conversation_id' => 50, 'user_id' => 9, 'body' => 'yo']);

    app(MessengerSync::class)->wipeMirror();

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(User::whereKey(1)->exists())->toBeTrue()   // jordan account
        ->and(User::whereKey(2)->exists())->toBeTrue()   // maya account
        ->and(User::whereKey(9)->exists())->toBeFalse(); // diego contact, gone
});

it('switches account from Settings — new one is current, mirror is fresh', function () {
    config(['services.messenger.url' => null]);   // offline: switchTo() won't hydrate

    $jordan = account('jordan', 1, current: true);
    $maya = account('maya', 2);

    $convo = Conversation::create(['is_group' => false]);
    $convo->id = 60;
    $convo->save();
    $convo->participants()->attach([1, 2]);
    Message::create(['conversation_id' => 60, 'user_id' => 2, 'body' => 'hi jordan']);

    Native::test(Settings::class)->call('switchAccount', $maya->id)->assertReplacedWith('/');

    expect(Account::current()->username)->toBe('maya')
        ->and(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0);
});

it('signs out — falls back to the other account, else onboarding', function () {
    config(['services.messenger.url' => null]);

    account('jordan', 1, current: true);
    account('maya', 2);

    Native::test(Settings::class)->call('signOut')->assertReplacedWith('/');
    expect(Account::count())->toBe(1)
        ->and(Account::current()->username)->toBe('maya');

    Native::test(Settings::class)->call('signOut')->assertReplacedWith('/welcome');
    expect(Account::count())->toBe(0);
});

it('add account navigates to the welcome screen', function () {
    account('jordan', 1, current: true);

    Native::test(Settings::class)->call('addAccount')->assertNavigatedTo('/welcome');
});
