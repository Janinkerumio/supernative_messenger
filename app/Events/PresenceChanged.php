<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PresenceChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public User $user, public bool $online)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        // Notify every conversation this user is part of.
        return $this->user->conversations()
            ->pluck('conversations.id')
            ->map(fn ($id) => new PrivateChannel('conversations.'.$id))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'presence.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            // Respect the privacy flag on the wire, not just in the UI.
            'online' => $this->online && $this->user->active_status_visible,
        ];
    }
}
