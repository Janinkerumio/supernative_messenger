<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Register (or re-attach) a device and issue a Sanctum token.
     *
     * The demo has no password flow: the mobile app sends its local identity
     * and we bind a token to the matching server user, creating one if needed.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'username' => ['nullable', 'string', 'max:40'],
            'name' => ['required_without:user_id', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'unknown'])],
            'push_token' => ['nullable', 'string', 'max:512'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = match (true) {
            isset($data['user_id']) => User::findOrFail($data['user_id']),
            isset($data['username']) => User::firstOrCreate(
                ['username' => Str::lower($data['username'])],
                [
                    'name' => $data['name'] ?? Str::title($data['username']),
                    'email' => $data['email'] ?? Str::lower($data['username']).'@supernative.app',
                    'password' => Str::random(32),
                ]
            ),
            default => User::firstOrCreate(
                ['email' => $data['email'] ?? Str::slug($data['name']).'@supernative.app'],
                ['name' => $data['name'], 'password' => Str::random(32)],
            ),
        };

        $device = $user->devices()->updateOrCreate(
            $data['push_token'] ?? false
                ? ['push_token' => $data['push_token']]
                : ['name' => $data['device_name'] ?? 'device', 'platform' => $data['platform'] ?? 'unknown'],
            [
                'name' => $data['device_name'] ?? 'device',
                'platform' => $data['platform'] ?? 'unknown',
                'push_token' => $data['push_token'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        // One live token per device.
        $tokenName = 'device:'.$device->id;
        $user->tokens()->where('name', $tokenName)->delete();
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
