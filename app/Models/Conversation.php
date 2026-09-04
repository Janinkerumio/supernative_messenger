<?php

namespace App\Models;

use Illuminate\Support\Carbon;
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

    public function lastReadAtFor(User $user): ?Carbon
    {
        $pivot = $this->participants->firstWhere('id', $user->id)?->pivot;
        $value = $pivot?->last_read_at;

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * The timestamp through which every *other* participant has read — used to
     * render "Seen" on the sender's own messages. Null when a receipt can't be
     * shown (nobody has read, or a party has read receipts disabled).
     */
    public function othersReadThrough(User $me): ?Carbon
    {
        $others = $this->participants->where('id', '!=', $me->id);

        if ($others->isEmpty() || $others->contains(fn (User $u) => ! $u->read_receipts_enabled)) {
            return null;
        }

        $stamps = $others
            ->map(fn (User $u) => $this->lastReadAtFor($u))
            ->filter();

        return $stamps->count() === $others->count() ? $stamps->min() : null;
    }

    public function markReadBy(User $user, ?Carbon $at = null): void
    {
        $this->participants()->updateExistingPivot($user->id, [
            'last_read_at' => $at ?? now(),
        ]);
    }
}
