<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentCheck extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'created_at' => 'datetime'];
    }
}
