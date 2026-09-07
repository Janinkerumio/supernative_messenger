<?php

use App\NativeComponents\ConvoList;
use App\NativeComponents\ConvoShow;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\NewChat;
use App\NativeComponents\Onboarding;
use App\NativeComponents\People;
use App\NativeComponents\Settings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SuperNative Messenger — native screens
|--------------------------------------------------------------------------
|
| Every screen is a NativeComponent rendered by the Edge runtime. The
| TabsLayout gives them the shared NavigationStack + tab bar chrome.
|
*/

// First-launch identity setup — no tab bar / nav chrome (outside the group).
Route::native('/welcome', Onboarding::class);

Route::nativeGroup(TabsLayout::class, function () {
    Route::native('/', ConvoList::class);
    Route::native('/people', People::class);
    Route::native('/settings', Settings::class);

    Route::native('/new-chat', NewChat::class);
    Route::native('/chats/{conversation}', ConvoShow::class);
});
