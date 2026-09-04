<?php

namespace App\Http\Controllers\Api;

use App\Events\PresenceChanged;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'online' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        // Privacy flag: a user who hides their active status can never be
        // reported online, regardless of what the client sends.
        $online = $data['online'] && $user->active_status_visible;

        $changed = $user->is_online !== $online;

        $user->forceFill([
            'is_online' => $online,
            'last_seen_at' => now(),
        ])->save();

        if ($changed) {
            broadcast(new PresenceChanged($user, $online))->toOthers();
        }

        return response()->json([
            'online' => $online,
            'visible' => (bool) $user->active_status_visible,
        ]);
    }
}
