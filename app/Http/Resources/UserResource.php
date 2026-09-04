<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'tagline' => $this->tagline,
            'accent' => $this->accent,
            'initials' => $this->initials(),
            'online' => $this->showsOnlineDotTo($viewer),
            'presence_label' => $this->presenceLabel($viewer),
            $this->mergeWhen($viewer !== null && $viewer->is($this->resource), [
                'email' => $this->email,
                'active_status_visible' => (bool) $this->active_status_visible,
                'read_receipts_enabled' => (bool) $this->read_receipts_enabled,
                'theme_preference' => $this->theme_preference,
            ]),
        ];
    }
}
