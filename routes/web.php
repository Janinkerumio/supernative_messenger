<?php

use App\NativeComponents\ConvoList;
use App\NativeComponents\ConvoShow;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\NewChat;
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

Route::nativeGroup(TabsLayout::class, function () {
    Route::native('/', ConvoList::class);
    Route::native('/people', People::class);
    Route::native('/settings', Settings::class);

    Route::native('/new-chat', NewChat::class);
    Route::native('/chats/{conversation}', ConvoShow::class);
});
