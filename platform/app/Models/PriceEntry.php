<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime', 'created_at' => 'datetime', 'input_per_million' => 'decimal:4', 'output_per_million' => 'decimal:4'];
    }
}
