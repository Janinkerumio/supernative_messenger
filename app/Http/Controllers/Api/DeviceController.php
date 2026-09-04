<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceController extends Controller
{
    /** Register / refresh the FCM (Android) or APNs (iOS) push token for this device. */
    public function updateToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'unknown'])],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();

        // Make sure the same token isn't attached to another user.
        $user->devices()->getRelated()->newQuery()
            ->where('push_token', $data['push_token'])
            ->where('user_id', '!=', $user->id)
            ->delete();

        $device = $user->devices()->updateOrCreate(
            ['push_token' => $data['push_token']],
            [
                'platform' => $data['platform'] ?? 'unknown',
                'name' => $data['device_name'] ?? 'device',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['ok' => true, 'device_id' => $device->id]);
    }
}
