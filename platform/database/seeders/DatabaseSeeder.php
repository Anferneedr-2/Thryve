<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// No default accounts: the first Super Admin is created by the install wizard (P02.02).
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
