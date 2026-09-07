<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A local record of an identity this device has signed in as. Multi-account:
 * many rows, exactly one `is_current`. Switching accounts swaps the active
 * bearer token and wipes the conversation mirror so one account never sees
 * another's threads.
 */
class Account extends Model
{
    protected $fillable = ['username', 'name', 'accent', 'server_id', 'is_current', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first();
    }

    /** The mirrored User row for this account, if it has synced yet. */
    public function user(): ?User
    {
        return $this->server_id ? User::find($this->server_id) : null;
    }

    /** SecureStorage / cache key under which this account's API token is stored. */
    public function tokenKey(): string
    {
        return 'messenger.api_token.'.$this->username;
    }

    /** Make this the active account (exactly one row is current). */
    public function makeCurrent(): void
    {
        DB::transaction(function () {
            static::query()->where('id', '!=', $this->id)->update(['is_current' => false]);
            $this->forceFill(['is_current' => true, 'last_used_at' => now()])->save();
        });
    }
}
