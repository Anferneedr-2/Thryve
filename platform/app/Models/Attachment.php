<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $guarded = [];

    protected $hidden = ['storage_key', 'extracted_text'];

    protected function casts(): array
    {
        return ['truncated' => 'boolean', 'generated' => 'boolean'];
    }
}
