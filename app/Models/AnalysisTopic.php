<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry of the managed topic list. Deactivated, never deleted, so stored analyses stay readable. */
class AnalysisTopic extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_fallback' => 'boolean'];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('priority')->orderBy('id');
    }
}
