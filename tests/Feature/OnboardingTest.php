<?php

use App\Models\Account;
use App\Models\User;
use App\NativeComponents\ConvoList;
use App\NativeComponents\Onboarding;
use App\Support\DemoWorld;
use Native\Mobile\Testing\Native;

it('does not seed the demo world when disabled', function () {
    putenv('SEED_DEMO_WORLD=false');
    $_ENV['SEED_DEMO_WORLD'] = 'false';

    expect(DemoWorld::enabled())->toBeFalse();

    DemoWorld::reseed();
    expect(User::count())->toBe(0)->and(Account::count())->toBe(0);

    putenv('SEED_DEMO_WORLD');
    unset($_ENV['SEED_DEMO_WORLD']);
});

it('seeds the demo world outside production by default', function () {
    config(['app.env' => 'local']);
    putenv('SEED_DEMO_WORLD');
    unset($_ENV['SEED_DEMO_WORLD'], $_SERVER['SEED_DEMO_WORLD']);

    expect(DemoWorld::enabled())->toBeTrue();

    DemoWorld::reseed();
    expect(Account::current()?->username)->toBe('jordan');
});

it('SEED_DEMO_WORLD=false overrides the non-production default', function () {
    config(['app.env' => 'local']);
    putenv('SEED_DEMO_WORLD=false');
    $_ENV['SEED_DEMO_WORLD'] = 'false';

    expect(DemoWorld::enabled())->toBeFalse();

    putenv('SEED_DEMO_WORLD');
    unset($_ENV['SEED_DEMO_WORLD']);
});

it('redirects to onboarding when there is no account', function () {
    expect(Account::count())->toBe(0);

    Native::test(ConvoList::class)->assertReplacedWith('/welcome');
});

it('shows the welcome screen and validates input', function () {
    $screen = Native::test(Onboarding::class);

    $screen->assertSee('Welcome to SuperNative');

    $screen->set('name', 'A')->set('username', 'ok')->call('start');
    $screen->assertNoNavigation();
    expect(Account::count())->toBe(0);

    $screen->set('name', 'Ada Lovelace')->set('username', 'ab')->call('start');
    $screen->assertNoNavigation();
    expect(Account::count())->toBe(0);
});

it('creates the account and enters the app', function () {
    config(['services.messenger.url' => null]);   // offline: no register call

    Native::test(Onboarding::class)
        ->set('name', '  Ada   Lovelace ')
        ->set('username', 'Ada.Lovelace!')
        ->call('start')
        ->assertReplacedWith('/');

    $account = Account::sole();
    expect($account->name)->toBe('Ada Lovelace')
        ->and($account->username)->toBe('adalovelace')
        ->and($account->is_current)->toBeTrue();
});

it('does not redirect once an account exists', function () {
    Account::create(['username' => 'sam', 'name' => 'Sam', 'is_current' => true]);

    Native::test(ConvoList::class)->assertNoNavigation();
    Native::test(Onboarding::class)->assertNoNavigation();   // form always shows now
});

it('switches to a known account instead of creating a duplicate', function () {
    config(['services.messenger.url' => null]);

    Account::create(['username' => 'jordan', 'name' => 'Jordan', 'is_current' => true]);
    $maya = Account::create(['username' => 'maya', 'name' => 'Maya', 'is_current' => false]);

    Native::test(Onboarding::class)
        ->set('name', 'Maya Chen')
        ->set('username', 'maya')
        ->call('start')
        ->assertReplacedWith('/');

    expect(Account::count())->toBe(2)
        ->and(Account::current()->id)->toBe($maya->id);
});
