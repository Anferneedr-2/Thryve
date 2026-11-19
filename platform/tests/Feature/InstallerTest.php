<?php

namespace Tests\Feature;

use App\Install\EnvFile;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    protected bool $installed = false;

    private function db(array $override = []): array
    {
        $c = config('database.connections.mariadb');

        return array_merge([
            'host' => $c['host'], 'port' => (int) $c['port'], 'database' => $c['database'],
            'username' => $c['username'], 'password' => (string) $c['password'],
        ], $override);
    }

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'database' => $this->db(),
            'hermes' => ['url' => '', 'key' => ''],
            'admin' => ['username' => 'owner', 'name' => 'Site Owner', 'email' => 'owner@example.com', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password'],
        ], $override);
    }

    /** Leave the shared test database clean and migrated so other test classes are unaffected. */
    private function resetDatabase(): void
    {
        Schema::dropAllTables();
        Artisan::call('migrate:fresh');
    }

    public function test_checks_report_requirements_and_permissions(): void
    {
        $r = $this->getJson('/api/install/checks')->assertOk();
        $r->assertJsonStructure(['data' => ['requirements' => [['key', 'label', 'ok', 'detail']], 'permissions' => [['key', 'label', 'ok', 'detail']], 'ready']]);
        $this->assertTrue($r->json('data.ready'));
        $this->assertContains('php', array_column($r->json('data.requirements'), 'key'));
    }

    public function test_other_api_routes_are_unavailable_until_installed_but_health_works(): void
    {
        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/me')->assertStatus(404);
        $this->getJson('/api/anything')->assertStatus(404);
    }

    public function test_installed_routes_report_not_installed_before_install(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'installed'])->get('/api/_t/needs-install', fn () => ['ok' => true]);

        $this->getJson('/api/_t/needs-install')->assertStatus(503)->assertJsonPath('error.code', 'not_installed');
    }

    public function test_installer_endpoints_vanish_once_installed(): void
    {
        file_put_contents($this->tmpDir.'/installed.json', '{}');

        $this->getJson('/api/install/checks')->assertStatus(404);
        $this->postJson('/api/install/run', $this->payload())->assertStatus(404);
        $this->postJson('/api/install/test-database', $this->payload())->assertStatus(404);
    }

    public function test_database_test_succeeds_with_good_credentials_and_hides_secrets(): void
    {
        $r = $this->postJson('/api/install/test-database', ['database' => $this->db()])->assertOk();
        $this->assertTrue($r->json('data.ok'));
        $this->assertNotEmpty($r->json('data.version'));
    }

    public function test_database_test_gives_actionable_non_secret_errors(): void
    {
        $bad = $this->postJson('/api/install/test-database', ['database' => $this->db(['password' => 'definitely-wrong-pw'])])->assertOk();
        $this->assertFalse($bad->json('data.ok'));
        $this->assertStringContainsString('username or password', $bad->json('data.message'));
        $this->assertStringNotContainsString('definitely-wrong-pw', $bad->getContent());

        $missing = $this->postJson('/api/install/test-database', ['database' => $this->db(['database' => 'no_such_db_xyz'])])->assertOk();
        $this->assertStringContainsString('does not exist', $missing->json('data.message'));

        $down = $this->postJson('/api/install/test-database', ['database' => $this->db(['port' => 1])])->assertOk();
        $this->assertStringContainsString('Could not reach', $down->json('data.message'));
    }

    public function test_database_input_is_validated(): void
    {
        $this->postJson('/api/install/test-database', ['database' => ['host' => 'bad host!', 'port' => 99999, 'database' => 'x;drop', 'username' => '']])
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['database.host', 'database.port', 'database.database', 'database.username']]]);
    }

    public function test_hermes_test_is_reachability_only(): void
    {
        Http::fake(['*' => Http::response('', 401)]);
        $ok = $this->postJson('/api/install/test-hermes', ['hermes' => ['url' => 'https://hermes.example.com', 'key' => 'k']])->assertOk();
        $this->assertTrue($ok->json('data.reachable'));
        $this->assertSame(401, $ok->json('data.status'));

        $bad = $this->postJson('/api/install/test-hermes', ['hermes' => ['url' => 'ftp://example.com']])->assertOk();
        $this->assertFalse($bad->json('data.ok'));

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));
        $down = $this->postJson('/api/install/test-hermes', ['hermes' => ['url' => 'https://hermes.example.com']])->assertOk();
        $this->assertFalse($down->json('data.reachable'));
    }

    public function test_admin_input_is_validated_before_anything_runs(): void
    {
        $short = $this->postJson('/api/install/run', $this->payload(['admin' => ['password' => 'short', 'password_confirmation' => 'short']]))
            ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->assertArrayHasKey('admin.password', $short->json('error.details'));

        $this->postJson('/api/install/run', $this->payload(['admin' => ['password_confirmation' => 'different-password-1']]))->assertStatus(422);
        $this->postJson('/api/install/run', $this->payload(['admin' => ['username' => 'a b']]))->assertStatus(422);

        $this->assertFileDoesNotExist($this->tmpDir.'/installed.json');
        $this->assertFileDoesNotExist($this->tmpDir.'/.env');
    }

    public function test_failed_database_step_changes_nothing_and_a_retry_succeeds(): void
    {
        $fail = $this->postJson('/api/install/run', $this->payload(['database' => ['password' => 'wrong-pw-here']]))->assertStatus(422);
        $fail->assertJsonPath('error.code', 'install_failed')->assertJsonPath('error.details.step', 'database');
        $this->assertStringNotContainsString('wrong-pw-here', $fail->getContent());
        $this->assertFileDoesNotExist($this->tmpDir.'/installed.json');
        $this->assertFileDoesNotExist($this->tmpDir.'/.env');

        try {
            Schema::dropAllTables();
            $this->postJson('/api/install/run', $this->payload())->assertOk();
            $this->assertFileExists($this->tmpDir.'/installed.json');
        } finally {
            $this->resetDatabase();
        }
    }

    public function test_full_install_creates_everything_and_locks_the_installer(): void
    {
        try {
            Schema::dropAllTables();
            $r = $this->postJson('/api/install/run', $this->payload(['hermes' => ['url' => 'https://hermes.example.com', 'key' => 'secret-hermes-key']]))->assertOk();

            $this->assertSame(['database', 'environment', 'migrate', 'seed', 'admin', 'finish'], array_column($r->json('data.steps'), 'key'));
            $this->assertStringNotContainsString('a-long-test-password', $r->getContent());
            $this->assertStringNotContainsString('secret-hermes-key', $r->getContent());

            $admin = User::where('username', 'owner')->firstOrFail();
            $this->assertSame(Role::SUPER_ADMIN, $admin->role->slug);
            $this->assertTrue($admin->is_active);
            $this->assertTrue(Hash::check('a-long-test-password', $admin->password));
            $this->assertSame(3, Role::count());
            $this->assertSame(1, AuditEntry::where('action', 'system.installed')->where('actor_id', $admin->id)->count());

            $env = file_get_contents($this->tmpDir.'/.env');
            $this->assertStringContainsString('DB_CONNECTION=mariadb', $env);
            $this->assertStringContainsString('APP_ENV=production', $env);
            $this->assertStringContainsString('APP_DEBUG=false', $env);
            $this->assertStringContainsString('HERMES_URL=https://hermes.example.com', $env);
            $this->assertStringNotContainsString('a-long-test-password', $env);
            $this->assertMatchesRegularExpression('/^APP_KEY=base64:\S{40,}$/m', $env);
            $key = (string) \Dotenv\Dotenv::parse($env)['APP_KEY'];

            $this->postJson('/api/install/run', $this->payload())->assertStatus(404);
            $this->assertSame(1, User::count());
        } finally {
            $this->resetDatabase();
        }
    }

    public function test_rerun_after_interruption_keeps_the_existing_super_admin(): void
    {
        try {
            Schema::dropAllTables();
            $this->postJson('/api/install/run', $this->payload())->assertOk();
            unlink($this->tmpDir.'/installed.json'); // simulate a run that died before the final step

            $keyBefore = \Dotenv\Dotenv::parse((string) file_get_contents($this->tmpDir.'/.env'))['APP_KEY'];
            $r = $this->postJson('/api/install/run', $this->payload(['admin' => ['username' => 'other', 'password' => 'another-long-password', 'password_confirmation' => 'another-long-password']]))->assertOk();
            $this->assertSame($keyBefore, \Dotenv\Dotenv::parse((string) file_get_contents($this->tmpDir.'/.env'))['APP_KEY'], 'retry must not rotate the app key');

            $admin = collect($r->json('data.steps'))->firstWhere('key', 'admin');
            $this->assertSame('skipped', $admin['status']);
            $this->assertSame(1, User::count());
            $this->assertSame('owner', User::first()->username);
            $this->assertFileExists($this->tmpDir.'/installed.json');
        } finally {
            $this->resetDatabase();
        }
    }

    public function test_env_file_updates_appends_preserves_and_quotes(): void
    {
        $path = $this->tmpDir.'/.env';
        file_put_contents($path, "APP_NAME=Thryve\nDB_HOST=old\n# keep me\n");

        EnvFile::set($path, ['DB_HOST' => 'new.host', 'DB_PASSWORD' => "p\$a\"ss'w\\d", 'EMPTY' => '']);
        $env = file_get_contents($path);

        $this->assertStringContainsString("APP_NAME=Thryve\n", $env);
        $this->assertStringContainsString('# keep me', $env);
        $this->assertStringContainsString('DB_HOST=new.host', $env);
        $this->assertStringNotContainsString('DB_HOST=old', $env);
        $this->assertStringContainsString('DB_PASSWORD="p\\$a\\"ss\'w\\\\d"', $env);
        $this->assertStringContainsString("EMPTY=\n", $env);

        // The written value must read back identically through Laravel's own dotenv parser.
        $parsed = \Dotenv\Dotenv::parse((string) file_get_contents($path));
        $this->assertSame("p\$a\"ss'w\\d", $parsed['DB_PASSWORD']);
        $this->assertSame('new.host', $parsed['DB_HOST']);
    }

    public function test_env_file_strips_a_bom_from_its_source(): void
    {
        $path = $this->tmpDir.'/.env';
        file_put_contents($path, "\xEF\xBB\xBFAPP_NAME=Thryve\nAPP_KEY=\n");

        EnvFile::set($path, ['APP_KEY' => 'base64:abc123+/=']);

        $env = file_get_contents($path);
        $this->assertStringStartsWith('APP_NAME=Thryve', $env);
        $this->assertStringContainsString("APP_KEY=base64:abc123+/=\n", $env);
    }

    public function test_env_file_rejects_line_breaks_and_bad_keys(): void
    {
        $path = $this->tmpDir.'/.env';
        $this->expectException(\InvalidArgumentException::class);
        EnvFile::set($path, ['DB_PASSWORD' => "a\nEVIL=1"]);
    }

    public function test_env_file_rejects_bad_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnvFile::set($this->tmpDir.'/.env', ['bad-key' => 'x']);
    }
}
