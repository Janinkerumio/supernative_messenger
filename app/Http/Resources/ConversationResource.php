<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $me */
        $me = $request->user();
        $other = $this->is_group ? null : $this->counterpart($me);
        $othersReadThrough = $this->othersReadThrough($me);

        return [
            'id' => $this->id,
            'is_group' => (bool) $this->is_group,
            'title' => $this->titleFor($me),
            'preview' => $this->previewFor($me),
            'initials' => $this->is_group ? '#' : ($other?->initials() ?? '?'),
            'accent' => $this->is_group ? '#8E8E93' : ($other?->accent ?? '#0A7CFF'),
            'online' => $other?->showsOnlineDotTo($me) ?? false,
            'presence_label' => $other?->presenceLabel($me),
            'last_message_at' => optional($this->last_message_at)->toIso8601String(),
            'my_last_read_at' => optional($this->lastReadAtFor($me))->toIso8601String(),
            'others_read_through' => optional($othersReadThrough)->toIso8601String(),
            'participants' => UserResource::collection($this->whenLoaded('participants')),
        ];
    }
}
