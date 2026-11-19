<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'computed_first_in_at' => 'datetime', 'computed_last_out_at' => 'datetime',
            'first_in_override' => 'datetime', 'last_out_override' => 'datetime',
            'is_corrected' => 'boolean',
        ];
    }

    // Official values: a correction wins over what the system measured.
    public function activeSeconds(): int
    {
        return $this->active_seconds_override ?? $this->computed_active_seconds;
    }

    public function firstIn()
    {
        return $this->first_in_override ?? $this->computed_first_in_at;
    }

    public function lastOut()
    {
        return $this->last_out_override ?? $this->computed_last_out_at;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class)->orderBy('id');
    }
}
