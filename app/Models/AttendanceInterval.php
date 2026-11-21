<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceInterval extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'work_date' => 'date:Y-m-d'];
    }
}
