<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PresenceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SuperNative Messenger API (server role)
|--------------------------------------------------------------------------
|
| The mobile build talks to these endpoints when MESSENGER_API_URL is set.
| Auth is a Sanctum bearer token issued by POST /auth/register-device.
|
*/

Route::post('auth/register-device', [AuthController::class, 'registerDevice']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me/settings', [MeController::class, 'updateSettings']);

    Route::get('contacts', ContactController::class);

    Route::get('conversations', [ConversationController::class, 'index']);
    Route::post('conversations', [ConversationController::class, 'store']);
    Route::get('conversations/{conversation}', [ConversationController::class, 'show']);

    Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
    Route::post('conversations/{conversation}/messages', [MessageController::class, 'store']);
    Route::post('conversations/{conversation}/read', [MessageController::class, 'markRead']);

    Route::post('devices/token', [DeviceController::class, 'updateToken']);

    Route::post('presence', PresenceController::class);
});
