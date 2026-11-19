<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $guarded = [];

    // Keys never leave the server in a response; the one-time reveal is built by hand in the controller.
    protected $hidden = ['outbound_key', 'pending_key', 'inbound_key_hash'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean', 'is_default' => 'boolean',
            'outbound_key' => 'encrypted', 'pending_key' => 'encrypted',
            'inbound_last_seen_at' => 'datetime', 'last_checked_at' => 'datetime', 'removed_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(AgentCheck::class);
    }

    public function scopeActive($q)
    {
        return $q->whereNull('removed_at');
    }

    /** Full address of this agent's Hermes profile. */
    public function url(?string $profile = null): string
    {
        $profile ??= $this->profile;

        return rtrim($this->base_url, '/').($profile ? "/p/{$profile}" : '');
    }
}
