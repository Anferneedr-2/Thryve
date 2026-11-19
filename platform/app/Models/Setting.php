<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// Raw storage only. Reads and writes go through the settings service (P02.06), which restricts writes to Super Admin.
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
