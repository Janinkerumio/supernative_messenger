<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['name', 'is_group', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasMany
    {
        return $this->messages()->latest()->limit(1);
    }

    /** The other person in a 1:1 conversation, relative to $me. */
    public function counterpart(User $me): ?User
    {
        return $this->participants->firstWhere('id', '!=', $me->id);
    }

    public function titleFor(User $me): string
    {
        if ($this->is_group) {
            return $this->name
                ?: $this->participants->where('id', '!=', $me->id)->pluck('name')->join(', ');
        }

        return $this->counterpart($me)?->name ?? 'Unknown';
    }

    public function previewFor(User $me): string
    {
        $message = $this->messages->last() ?? $this->messages()->latest()->first();

        if (! $message) {
            return 'Say hello 👋';
        }

        $who = $message->user_id === $me->id ? 'You: ' : '';

        return $who.str($message->body)->limit(38);
    }
}
