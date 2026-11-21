<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name'])]
class Role extends Model
{
    public const EMPLOYEE = 'employee';
    public const ADMIN = 'admin';
    public const SUPER_ADMIN = 'super_admin';

    /** slug => display name; the only roles the MVP knows about. */
    public const ALL = [
        self::EMPLOYEE => 'Employee',
        self::ADMIN => 'Admin',
        self::SUPER_ADMIN => 'Super Admin',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
