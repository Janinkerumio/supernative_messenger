<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Services\Push\FcmSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

class SendMessagePush implements ShouldQueue
{
    public function __construct(private readonly FcmSender $fcm)
    {
    }

    public function handle(MessageSent $event): void
    {
        if (! $this->fcm->enabled()) {
            return;
        }

        $message = $event->message->loadMissing(['user', 'conversation.participants.devices']);
        $conversation = $message->conversation;

        $recipients = $conversation->participants
            ->reject(fn ($user) => $user->id === $message->user_id);

        $tokens = $recipients
            ->flatMap(fn ($user) => $user->devices->pluck('push_token'))
            ->filter()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $title = $conversation->is_group
            ? $conversation->titleFor($recipients->first()).' · '.$message->user->name
            : $message->user->name;

        $this->fcm->send(
            tokens: $tokens,
            title: $title,
            body: Str::limit($message->body, 140),
            data: [
                'conversation_id' => (string) $conversation->id,
                'message_id' => (string) $message->id,
                'type' => 'message',
            ],
        );
    }
}
