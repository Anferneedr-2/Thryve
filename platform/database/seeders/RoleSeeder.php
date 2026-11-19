<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

// Idempotent: safe to run on every install and update.
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::ALL as $slug => $name) {
            Role::updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
