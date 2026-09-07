<?php

use App\Models\User;
use App\NativeComponents\ConvoList;
use App\NativeComponents\Onboarding;
use App\Support\DemoWorld;
use Native\Mobile\Testing\Native;

it('does not seed the demo world in production', function () {
    config(['app.env' => 'production']);
    // env() override so DemoWorld::enabled() (which reads env, not config) sees it
    putenv('SEED_DEMO_WORLD');
    $_ENV['SEED_DEMO_WORLD'] = null;

    expect(DemoWorld::enabled())->toBeFalse();

    DemoWorld::ensure();
    expect(User::count())->toBe(0);
});

it('seeds the demo world outside production by default', function () {
    config(['app.env' => 'local']);
    putenv('SEED_DEMO_WORLD');
    unset($_ENV['SEED_DEMO_WORLD'], $_SERVER['SEED_DEMO_WORLD']);

    expect(DemoWorld::enabled())->toBeTrue();
});

it('SEED_DEMO_WORLD=false overrides the non-production default', function () {
    config(['app.env' => 'local']);
    putenv('SEED_DEMO_WORLD=false');
    $_ENV['SEED_DEMO_WORLD'] = 'false';

    expect(DemoWorld::enabled())->toBeFalse();

    putenv('SEED_DEMO_WORLD');
    unset($_ENV['SEED_DEMO_WORLD']);
});

it('redirects to onboarding when there is no local user', function () {
    expect(User::count())->toBe(0);

    Native::test(ConvoList::class)->assertReplacedWith('/welcome');
});

it('shows the welcome screen and validates input', function () {
    $screen = Native::test(Onboarding::class);

    $screen->assertSee('Welcome to SuperNative');

    $screen->set('name', 'A')->set('username', 'ok')->call('start');
    $screen->assertNoNavigation();
    expect(User::count())->toBe(0);

    $screen->set('name', 'Ada Lovelace')->set('username', 'ab')->call('start');
    $screen->assertNoNavigation();
    expect(User::count())->toBe(0);
});

it('creates the local user and enters the app', function () {
    Native::test(Onboarding::class)
        ->set('name', '  Ada   Lovelace ')
        ->set('username', 'Ada.Lovelace!')
        ->call('start')
        ->assertReplacedWith('/');

    $user = User::sole();
    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->username)->toBe('adalovelace')
        ->and($user->is_online)->toBeTrue();
});

it('does not redirect once a user exists', function () {
    User::factory()->create();

    Native::test(ConvoList::class)->assertNoNavigation();
    Native::test(Onboarding::class)->assertReplacedWith('/');
});
