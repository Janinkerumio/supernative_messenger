<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateSettings(Request $request): UserResource
    {
        $data = $request->validate([
            'active_status_visible' => ['sometimes', 'boolean'],
            'read_receipts_enabled' => ['sometimes', 'boolean'],
            'theme_preference' => ['sometimes', Rule::in(['system', 'light', 'dark'])],
        ]);

        $user = $request->user();
        $user->fill($data);

        // Turning active status off immediately drops the online flag.
        if (array_key_exists('active_status_visible', $data) && ! $data['active_status_visible']) {
            $user->is_online = false;
        }

        $user->save();

        return new UserResource($user->refresh());
    }
}
