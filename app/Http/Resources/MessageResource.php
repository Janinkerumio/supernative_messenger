<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_uuid' => $this->client_uuid,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'sender' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'initials' => $this->user->initials(),
                'accent' => $this->user->accent,
            ]),
            'body' => $this->body,
            'mine' => $request->user()?->id === $this->user_id,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
