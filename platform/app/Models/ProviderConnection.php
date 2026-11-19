<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderConnection extends Model
{
    public const KINDS = ['claude', 'chatgpt'];

    protected $guarded = [];

    // The secret never leaves the server: encrypted at rest and hidden from every serialisation.
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
            'is_enabled' => 'boolean',
            'quota_known' => 'boolean',
            'status_checked_at' => 'datetime',
            'unavailable_until' => 'datetime',
            'quota_resets_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function usage(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function scopeActive($q)
    {
        return $q->whereNull('removed_at');
    }
}
