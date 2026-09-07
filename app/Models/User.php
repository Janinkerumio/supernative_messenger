<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable([
    'name', 'username', 'email', 'password', 'tagline', 'accent',
    'is_online', 'last_seen_at',
    'active_status_visible', 'read_receipts_enabled', 'theme_preference',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_online' => 'boolean',
            'active_status_visible' => 'boolean',
            'read_receipts_enabled' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Two uppercase initials for the avatar monogram. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = Str::substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? Str::substr($parts[count($parts) - 1], 0, 1) : '';

        return Str::upper($first.$last) ?: Str::upper(Str::substr($this->name, 0, 2));
    }

    /** Whether this user's online state may be shown to $viewer. */
    public function presenceVisibleTo(?User $viewer): bool
    {
        if (! $this->active_status_visible) {
            return false;
        }

        // Symmetric: if the viewer hides their own status, they don't get to
        // see anyone else's.
        return $viewer === null || $viewer->active_status_visible;
    }

    public function presenceLabel(?User $viewer = null): string
    {
        if (! $this->presenceVisibleTo($viewer)) {
            return 'Offline';
        }

        if ($this->is_online) {
            return 'Active now';
        }

        return $this->last_seen_at
            ? 'Active '.$this->last_seen_at->diffForHumans(null, true).' ago'
            : 'Offline';
    }

    public function showsOnlineDotTo(?User $viewer = null): bool
    {
        return $this->is_online && $this->presenceVisibleTo($viewer);
    }
}
