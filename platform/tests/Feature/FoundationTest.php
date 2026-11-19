<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_database_is_mariadb_and_reachable(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        $this->assertSame(1, (int) DB::selectOne('select 1 as ok')->ok);
    }

    public function test_spa_shell_is_served(): void
    {
        $this->get('/')->assertOk()->assertSee('id="root"', false);
    }
}
