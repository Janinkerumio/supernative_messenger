<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = ['conversation_id', 'user_id', 'body', 'client_uuid'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mine(User $me): bool
    {
        return $this->user_id === $me->id;
    }

    public function timeLabel(): string
    {
        return $this->created_at->isToday()
            ? $this->created_at->format('g:i A')
            : $this->created_at->format('M j, g:i A');
    }
}
