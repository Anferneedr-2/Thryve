<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TokenGrant extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }
}
