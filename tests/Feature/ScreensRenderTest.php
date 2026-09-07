<?php

use App\Models\Conversation;
use App\Models\User;
use App\NativeComponents\ConvoList;
use App\NativeComponents\ConvoShow;
use App\NativeComponents\NewChat;
use App\NativeComponents\Onboarding;
use App\NativeComponents\People;
use App\NativeComponents\Settings;
use Native\Mobile\Testing\Native;

/** A minimal local world so every screen has something to render. */
function seedWorld(): array
{
    $me = User::factory()->create(['name' => 'Jordan Rivera', 'username' => 'jordan']);
    $friend = User::factory()->create(['name' => 'Maya Chen', 'username' => 'maya']);

    $convo = Conversation::create(['is_group' => false]);
    $convo->participants()->attach([$me->id, $friend->id]);
    $convo->messages()->create(['user_id' => $friend->id, 'body' => 'hey there']);
    $convo->update(['last_message_at' => now()]);

    return [$me, $friend, $convo];
}

it('renders the chat list', function () {
    seedWorld();

    Native::test(ConvoList::class)->assertNoNavigation()->assertSee('Maya Chen');
});

it('renders a conversation thread', function () {
    [, , $convo] = seedWorld();

    Native::test(ConvoShow::class, ['conversation' => $convo->id])
        ->assertNoNavigation()
        ->assertSee('hey there');
});

it('renders the people list', function () {
    seedWorld();

    Native::test(People::class)->assertNoNavigation()->assertSee('Maya Chen');
});

it('renders the new-chat picker', function () {
    seedWorld();

    Native::test(NewChat::class)->assertNoNavigation()->assertSee('Maya Chen');
});

it('renders settings', function () {
    seedWorld();

    Native::test(Settings::class)->assertNoNavigation()->assertSee('Privacy');
});

it('renders onboarding when there is no user', function () {
    Native::test(Onboarding::class)->assertSee('Welcome to SuperNative');
});

it('sends a message locally when no API is configured', function () {
    config(['services.messenger.url' => null]);
    [$me, , $convo] = seedWorld();

    $before = $convo->messages()->count();

    Native::test(ConvoShow::class, ['conversation' => $convo->id])
        ->set('draft', 'sent from a test')
        ->call('send');

    expect($convo->messages()->count())->toBe($before + 1)
        ->and($convo->messages()->latest()->first()->user_id)->toBe($me->id);
});
