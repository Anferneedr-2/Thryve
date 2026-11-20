<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Notification;
use App\Models\Role;
use App\Updates\BackupService;
use App\Updates\Maintenance;
use App\Updates\UpdateSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\SimulatesDevices;
use Tests\Concerns\UsesUpdateSandbox;
use Tests\Support\RecordingSteps;
use Tests\TestCase;

class UpdateApiTest extends TestCase
{
    use RefreshDatabase, SimulatesDevices, UsesUpdateSandbox;

    private RecordingSteps $steps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabaseSessions();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->sandboxUp();
        $this->steps = new RecordingSteps;
        $this->app->instance(UpdateSteps::class, $this->steps);
    }

    protected function tearDown(): void
    {
        $this->sandboxDown();
        parent::tearDown();
    }

    private function upload(string $cookie, string $zip, ?string $sig, ?string $token = null)
    {
        $files = ['package' => new UploadedFile($zip, 'release.zip', 'application/zip', null, true)];
        if ($sig && is_file($sig)) {
            $files['signature'] = new UploadedFile($sig, 'release.zip.sig', 'text/plain', null, true);
        }
        $this->device($cookie);
        if ($token) {
            $this->unencryptedCookies = [Maintenance::COOKIE => $token];
        }

        return $this->post('/api/system/updates/stage', $files, ['Accept' => 'application/json']);
    }

    /** A request carrying the Super Admin's session and, once an update runs, the maintenance bypass cookie. */
    private function asSuper(string $cookie, ?string $token = null)
    {
        $this->device($cookie);
        if ($token) {
            $this->unencryptedCookies = [Maintenance::COOKIE => $token];
        }

        return $this;
    }

    private function stageAndApprove(string $cookie): string
    {
        [$zip, $sig] = $this->package('0.2.0');
        $this->upload($cookie, $zip, $sig)->assertOk();
        $r = $this->asSuper($cookie)->postJson('/api/system/updates/approve', ['confirm' => '0.2.0'])->assertOk();

        return $r->getCookie(Maintenance::COOKIE, false)->getValue();
    }

    // ---- access --------------------------------------------------------------------------------

    public function test_only_a_super_admin_can_touch_updates_or_backups(): void
    {
        $routes = [['get', '/api/system/updates'], ['post', '/api/system/updates/stage'], ['post', '/api/system/updates/approve'], ['post', '/api/system/updates/advance'],
            ['post', '/api/system/updates/rollback'], ['delete', '/api/system/updates/staged'], ['get', '/api/system/backups'], ['post', '/api/system/backups'], ['get', '/api/system/backups/20260101-000000-abcdef/verify']];

        foreach ($routes as [$m, $url]) {
            $this->device(null)->json($m, $url, [])->assertStatus(401);
        }
        foreach ([Role::EMPLOYEE, Role::ADMIN] as $role) {
            $c = $this->cookieFor($role);
            foreach ($routes as [$m, $url]) {
                $this->device($c)->json($m, $url, [])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
            }
        }
        $this->assertSame('idle', app(\App\Updates\UpdateManager::class)->state()['status']);
        $this->assertSame([], glob($this->sbx.'/backups/*'));
    }

    public function test_status_shows_the_version_and_never_secrets(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $r = $this->device($c)->getJson('/api/system/updates')->assertOk();

        $this->assertSame('0.1.0', $r->json('data.version'));
        $this->assertTrue($r->json('data.key_configured'));
        $this->assertSame([], $r->json('data.history'));
        foreach (['secret', 'public.pem', 'BEGIN', $this->sbx] as $leak) {
            $this->assertStringNotContainsString($leak, $r->getContent());
        }
    }

    // ---- staging over HTTP ---------------------------------------------------------------------

    public function test_a_signed_package_can_be_staged_and_is_audited(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        [$zip, $sig] = $this->package('0.2.0');

        $r = $this->upload($c, $zip, $sig)->assertOk();

        $this->assertSame('staged', $r->json('data.update.status'));
        $this->assertSame('0.2.0', $r->json('data.update.version'));
        $this->assertSame(1, AuditEntry::where('action', 'system.update_staged')->count());
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));

        // "Update available": the Super Admin is told a checked package is waiting, once per package.
        $this->assertSame(1, Notification::where('type', 'system.update')->where('title', 'like', '%0.2.0 is ready to review%')->count());
        $this->device($c)->deleteJson('/api/system/updates/staged')->assertOk();
        $this->upload($c, $zip, $sig)->assertOk();
        $this->assertSame(1, Notification::where('type', 'system.update')->where('title', 'like', '%ready to review%')->count(), 'the same package does not notify twice');
    }

    public function test_bad_uploads_are_rejected_with_a_clear_code(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $stranger = openssl_pkey_get_private(\App\Updates\OpenSsl::newKeyPair(2048)[0]);
        [$zip, $sig] = $this->package('0.2.0', signWith: $stranger);

        $this->upload($c, $zip, $sig)->assertStatus(422)->assertJsonPath('error.code', 'bad_signature');
        $this->upload($c, $zip, null)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        [$unsafe, $unsafeSig] = $this->package('0.3.0', ['.env' => 'APP_KEY=stolen']);
        $this->upload($c, $unsafe, $unsafeSig)->assertStatus(422)->assertJsonPath('error.code', 'forbidden_path');
        [$old, $oldSig] = $this->package('0.0.5');
        $this->upload($c, $old, $oldSig)->assertStatus(422)->assertJsonPath('error.code', 'not_newer');

        $this->assertSame('idle', app(\App\Updates\UpdateManager::class)->state()['status']);
        $this->assertSame(0, AuditEntry::where('action', 'system.update_staged')->count());
    }

    public function test_a_staged_package_can_be_discarded(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        [$zip, $sig] = $this->package('0.2.0');
        $this->upload($c, $zip, $sig)->assertOk();

        $this->device($c)->deleteJson('/api/system/updates/staged')->assertOk()->assertJsonPath('data.update.status', 'idle');
        $this->device($c)->deleteJson('/api/system/updates/staged')->assertStatus(409);
    }

    // ---- approval and maintenance mode ---------------------------------------------------------

    public function test_approval_needs_the_exact_version_and_starts_maintenance_with_a_bypass_cookie(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        [$zip, $sig] = $this->package('0.2.0');
        $this->upload($c, $zip, $sig)->assertOk();

        $this->device($c)->postJson('/api/system/updates/approve', [])->assertStatus(422);
        $this->device($c)->postJson('/api/system/updates/approve', ['confirm' => '9.9.9'])->assertStatus(422)->assertJsonPath('error.code', 'confirm_mismatch');
        $this->assertFalse(app(Maintenance::class)->active());

        $r = $this->device($c)->postJson('/api/system/updates/approve', ['confirm' => '0.2.0'])->assertOk()->assertJsonPath('data.update.status', 'running');
        $cookie = $r->getCookie(Maintenance::COOKIE, false);

        $this->assertTrue(app(Maintenance::class)->active());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cookie->getValue(), 'a plain token the maintenance check can read before anything else runs');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(0, $cookie->getExpiresTime(), 'lasts only for this browser session');
        $this->assertSame(1, AuditEntry::where('action', 'system.update_approved')->count());
        $this->assertStringNotContainsString($cookie->getValue(), $r->getContent(), 'the token is not in the response body');
    }

    public function test_during_maintenance_everyone_but_the_updating_admin_sees_the_notice(): void
    {
        $super = $this->cookieFor(Role::SUPER_ADMIN);
        $employee = $this->cookieFor(Role::EMPLOYEE);
        $token = $this->stageAndApprove($super);

        // A visitor opening the site sees the maintenance page.
        $page = $this->device(null)->get('/')->assertStatus(503);
        $this->assertStringContainsString('We are updating', $page->getContent());
        $this->assertStringContainsString('Updating to version 0.2.0', $page->getContent());
        $this->assertSame('120', $page->headers->get('Retry-After'));
        // API callers get the JSON envelope.
        $this->device(null)->getJson('/api/branding')->assertStatus(503)->assertJsonPath('error.code', 'maintenance');
        $this->device(null)->postJson('/api/auth/login', ['username' => 'x', 'password' => 'y'])->assertStatus(503);
        // A signed-in employee is held out too, even with a valid session.
        $this->device($employee)->getJson('/api/auth/me')->assertStatus(503)->assertJsonPath('error.code', 'maintenance');
        $this->device($employee)->postJson('/api/attendance/heartbeat')->assertStatus(503);
        // A forged or wrong bypass cookie does nothing.
        $this->asSuper($employee, str_repeat('a', 64))->getJson('/api/auth/me')->assertStatus(503);
        $this->asSuper($super, 'guess')->getJson('/api/system/updates')->assertStatus(503);
        // The health probe stays up.
        $this->device(null)->get('/up')->assertOk();
        // The Super Admin carrying the token keeps working, but the attendance heartbeat writes nothing during maintenance
        // (a row added between the backup and its check would make the backup look incomplete).
        $rows = fn () => \Illuminate\Support\Facades\DB::table('attendance_days')->count() + \Illuminate\Support\Facades\DB::table('attendance_intervals')->count();
        $before = $rows();
        $this->asSuper($super, $token)->postJson('/api/attendance/heartbeat')->assertOk()->assertJsonPath('data.paused', true);
        $this->asSuper($super, $token)->postJson('/api/attendance/close')->assertOk()->assertJsonPath('data.paused', true);
        $this->assertSame($before, $rows());
        $this->asSuper($super, $token)->getJson('/api/system/updates')->assertOk()->assertJsonPath('data.maintenance', true);
        $this->asSuper($super, $token)->getJson('/api/auth/me')->assertOk();
        // A different person's session with the admin's token is also let through (the token is the key); it is bound to this run only.
        app(Maintenance::class)->off();
        $this->asSuper($super, $token)->getJson('/api/system/updates')->assertOk();
        app(Maintenance::class)->on('second run');
        $this->asSuper($super, $token)->getJson('/api/system/updates')->assertStatus(503);
    }

    // ---- running the update --------------------------------------------------------------------

    public function test_an_update_runs_to_completion_over_http_and_lifts_maintenance(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'super@example.com']);
        [, $c] = $this->signIn($super);
        $token = $this->stageAndApprove($c);

        $steps = [];
        for ($i = 0; $i < 8; $i++) {
            $r = $this->asSuper($c, $token)->postJson('/api/system/updates/advance')->assertOk();
            $steps[] = $r->json('data.update.step');
            if ($r->json('data.update.status') === 'done') {
                break;
            }
        }

        $this->assertSame(['verify', 'files', 'migrate', 'seed', 'finalize', null], $steps, 'each call performs exactly one step');
        $this->assertFalse(app(Maintenance::class)->active());
        $this->assertSame("0.2.0\n", $this->appFile('VERSION'));
        $this->assertSame(1, AuditEntry::where('action', 'system.update_done')->count());
        $note = Notification::where('user_id', $super->id)->where('type', 'system.update')->first();
        $this->assertNotNull($note, 'the Super Admin gets an in-app notification');
        $this->assertStringContainsString('0.2.0', $note->title);

        $this->device(null)->getJson('/api/branding')->assertOk(); // everyone is back
        $status = $this->device($c)->getJson('/api/system/updates')->assertOk();
        $this->assertSame('0.2.0', $status->json('data.version'));
        $this->assertSame('applied', $status->json('data.history.0.result'));
    }

    public function test_a_failed_update_stays_in_maintenance_until_rolled_back(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'super@example.com']);
        [, $c] = $this->signIn($super);
        $this->steps->failMigrate = new \RuntimeException('migration exploded');
        $token = $this->stageAndApprove($c);

        for ($i = 0; $i < 8; $i++) {
            $r = $this->asSuper($c, $token)->postJson('/api/system/updates/advance')->assertOk();
            if ($r->json('data.update.status') === 'failed') {
                break;
            }
        }

        $this->assertSame('failed', $r->json('data.update.status'));
        $this->assertSame('migrate', $r->json('data.update.failed_step'));
        $this->assertTrue($r->json('data.maintenance'));
        $this->assertSame(1, AuditEntry::where('action', 'system.update_failed')->count());
        $this->device(null)->getJson('/api/branding')->assertStatus(503);
        $this->assertNotNull(Notification::where('user_id', $super->id)->where('title', 'like', '%failed%')->first());

        $this->asSuper($c, $token)->postJson('/api/system/updates/rollback', ['confirm' => 'sure'])->assertStatus(422)->assertJsonPath('error.code', 'confirm_mismatch');
        $this->assertTrue(app(Maintenance::class)->active());

        $done = $this->asSuper($c, $token)->postJson('/api/system/updates/rollback', ['confirm' => 'rollback'])->assertOk();
        $this->assertSame('rolled_back', $done->json('data.update.status'));
        $this->assertFalse(app(Maintenance::class)->active());
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));
        $this->assertNull($this->appFile('app/NewThing.php'));
        $this->assertSame(1, AuditEntry::where('action', 'system.update_rolled_back')->count(), 'recorded after the restore, so it survives it');
        $this->device(null)->getJson('/api/branding')->assertOk();
    }

    public function test_a_second_package_cannot_be_staged_while_an_update_is_running(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $token = $this->stageAndApprove($c);
        [$zip, $sig] = $this->package('0.3.0');

        $this->upload($c, $zip, $sig, $token)->assertStatus(409)->assertJsonPath('error.code', 'busy');
        $this->asSuper($c, $token)->deleteJson('/api/system/updates/staged')->assertStatus(409);
    }

    public function test_advance_does_nothing_without_a_running_update(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $this->device($c)->postJson('/api/system/updates/advance')->assertStatus(409);
        $this->device($c)->postJson('/api/system/updates/rollback', ['confirm' => 'rollback'])->assertStatus(409);
    }

    // ---- backups -------------------------------------------------------------------------------

    public function test_a_super_admin_can_back_up_now_list_and_verify(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);

        $made = $this->device($c)->postJson('/api/system/backups')->assertCreated();
        $id = $made->json('data.id');
        $this->assertSame('Manual backup', $made->json('data.label'));
        $this->assertArrayNotHasKey('db_sha256', $made->json('data'), 'internal details stay internal');

        $list = $this->device($c)->getJson('/api/system/backups')->assertOk();
        $this->assertSame([$id], array_column($list->json('data'), 'id'));
        $this->device($c)->getJson("/api/system/backups/{$id}/verify")->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(1, AuditEntry::where('action', 'system.backup_created')->count());

        file_put_contents($this->sbx.'/backups/'.$id.'/db.sql', 'tampered');
        $bad = $this->device($c)->getJson("/api/system/backups/{$id}/verify")->assertOk();
        $this->assertFalse($bad->json('data.ok'));
        $this->assertNotEmpty($bad->json('data.problems'));

        $this->device($c)->getJson('/api/system/backups/..%2F..%2Fetc/verify')->assertStatus(404);
        $this->device($c)->getJson('/api/system/backups/20260101-000000-abcdef/verify')->assertStatus(404);
    }

    // ---- database update after a code deploy (GitHub Action to cPanel, no terminal) -----------------------

    private const MIGRATION = '2026_11_19_000010_create_notifications_tables';

    /** Pretend the code on the server has one database change the database has not run yet. */
    private function makeDatabaseBehind(): int
    {
        $batch = (int) \Illuminate\Support\Facades\DB::table('migrations')->where('migration', self::MIGRATION)->value('batch');
        \Illuminate\Support\Facades\DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        return $batch;
    }

    public function test_when_code_is_deployed_ahead_of_the_database_everyone_but_the_super_admin_is_held_out(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'super@example.com']);
        [, $c] = $this->signIn($super);
        $employee = $this->cookieFor(Role::EMPLOYEE);

        $this->device($employee)->getJson('/api/auth/me')->assertOk();
        $this->assertFalse($this->device($c)->getJson('/api/auth/me')->json('data.database_update_needed'), 'nothing to do when the database is current');

        $batch = $this->makeDatabaseBehind();

        $this->device($employee)->getJson('/api/auth/me')->assertStatus(503)->assertJsonPath('error.code', 'maintenance');
        $this->device($employee)->getJson('/api/conversations')->assertStatus(503);
        $this->device($employee)->postJson('/api/auth/logout')->assertOk(); // they can still sign out
        $this->device($c)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.database_update_needed', true);
        $status = $this->device($c)->getJson('/api/system/updates')->assertOk();
        $this->assertTrue($status->json('data.database_behind'));
        $this->assertSame([self::MIGRATION], $status->json('data.pending_migrations'));

        \Illuminate\Support\Facades\DB::table('migrations')->insert(['migration' => self::MIGRATION, 'batch' => $batch]);
        $this->device($c)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.database_update_needed', false);
    }

    public function test_the_database_update_backs_up_migrates_and_lifts_the_hold_without_touching_files(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'super@example.com']);
        [, $c] = $this->signIn($super);
        $employee = $this->cookieFor(Role::EMPLOYEE);

        // Nothing to do, and a missing confirmation, are both refused.
        $this->asSuper($c)->postJson('/api/system/updates/database', ['confirm' => 'update'])->assertStatus(409)->assertJsonPath('error.code', 'nothing_to_do');
        $batch = $this->makeDatabaseBehind();
        $this->asSuper($c)->postJson('/api/system/updates/database', ['confirm' => 'yes please'])->assertStatus(422);
        $this->assertFalse(app(Maintenance::class)->active());

        $this->steps->onMigrate = fn () => \Illuminate\Support\Facades\DB::table('migrations')->insert(['migration' => self::MIGRATION, 'batch' => $batch]);
        $r = $this->asSuper($c)->postJson('/api/system/updates/database', ['confirm' => 'update'])->assertOk();
        $token = $r->getCookie(Maintenance::COOKIE, false)->getValue();
        $this->assertSame('database', $r->json('data.update.kind'));
        $this->assertTrue(app(Maintenance::class)->active());
        $this->device($employee)->getJson('/api/auth/me')->assertStatus(503);

        $steps = [];
        for ($i = 0; $i < 8; $i++) {
            $r = $this->asSuper($c, $token)->postJson('/api/system/updates/advance')->assertOk();
            $steps[] = $r->json('data.update.step');
            if ($r->json('data.update.status') === 'done') {
                break;
            }
        }

        $this->assertSame(['verify', 'migrate', 'seed', 'finalize', null], $steps, 'no file step: the code is already deployed');
        $this->assertSame(['migrate', 'seed', 'caches'], $this->steps->calls);
        $this->assertFalse(app(Maintenance::class)->active());
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'), 'a database update keeps the deployed version');
        $this->assertSame(1, AuditEntry::where('action', 'system.database_update_approved')->count());
        $status = $this->device($c)->getJson('/api/system/updates')->assertOk();
        $this->assertFalse($status->json('data.database_behind'));
        $this->assertSame('database', $status->json('data.history.0.kind'));
        $this->assertSame('applied', $status->json('data.history.0.result'));
        $this->assertSame('update', app(BackupService::class)->all()[0]['kind'], 'a backup was taken first');
        $this->assertNotNull(Notification::where('user_id', $super->id)->where('title', 'The database was updated')->first());
        $this->device($employee)->getJson('/api/auth/me')->assertOk(); // everyone is back
    }

    public function test_a_failed_database_update_stays_in_maintenance_until_rolled_back(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'super@example.com']);
        [, $c] = $this->signIn($super);
        $this->makeDatabaseBehind();
        $this->steps->failMigrate = new \RuntimeException('migration exploded');

        $r = $this->asSuper($c)->postJson('/api/system/updates/database', ['confirm' => 'update'])->assertOk();
        $token = $r->getCookie(Maintenance::COOKIE, false)->getValue();
        for ($i = 0; $i < 8; $i++) {
            $r = $this->asSuper($c, $token)->postJson('/api/system/updates/advance')->assertOk();
            if ($r->json('data.update.status') === 'failed') {
                break;
            }
        }
        $this->assertSame('failed', $r->json('data.update.status'));
        $this->assertSame('migrate', $r->json('data.update.failed_step'));
        $this->assertTrue(app(Maintenance::class)->active(), 'the site stays in maintenance');
        $this->asSuper($c, $token)->postJson('/api/system/updates/database', ['confirm' => 'update'])->assertStatus(409); // no second attempt on top

        $r = $this->asSuper($c, $token)->postJson('/api/system/updates/rollback', ['confirm' => 'rollback'])->assertOk();
        $this->assertSame('rolled_back', $r->json('data.update.status'));
        $this->assertFalse(app(Maintenance::class)->active());
    }

    public function test_a_database_update_that_cannot_make_its_backup_changes_nothing(): void
    {
        $super = $this->makeUser(Role::SUPER_ADMIN, ['email' => 'super@example.com']);
        [, $c] = $this->signIn($super);
        $this->makeDatabaseBehind();
        // A file where the backup folder should be: the backup cannot be created.
        $this->rmTree($this->sbx.'/backups');
        file_put_contents($this->sbx.'/backups', 'not a folder');

        $r = $this->asSuper($c)->postJson('/api/system/updates/database', ['confirm' => 'update'])->assertOk();
        $token = $r->getCookie(Maintenance::COOKIE, false)->getValue();
        $r = $this->asSuper($c, $token)->postJson('/api/system/updates/advance')->assertOk();

        $this->assertSame('idle', $r->json('data.update.status'), 'cancelled, not failed');
        $this->assertStringContainsString('cancelled before anything changed', $r->json('data.update.error.message'));
        $this->assertSame([], $this->steps->calls, 'the migration never ran');
        $this->assertFalse(app(Maintenance::class)->active());
    }

    public function test_the_maintenance_page_shows_the_logo_and_the_brand_images_stay_reachable(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('thryve.private_disk'));
        \Illuminate\Support\Facades\Storage::disk(config('thryve.private_disk'))->put('branding/logo.png', UploadedFile::fake()->image('l.png', 80, 30)->getContent());
        app(Maintenance::class)->on('Updating to version 0.2.0.');

        // The two public images are plain files, so the notice can use them; everything else is still held back.
        $this->device(null)->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->device(null)->get('/branding/logo_dark')->assertStatus(404); // none uploaded: not found, not "under maintenance"
        $this->device(null)->getJson('/api/branding')->assertStatus(503);

        $page = $this->device(null)->get('/')->assertStatus(503)->getContent();
        $this->assertStringContainsString('<img class="logo lt nodark" src="/branding/logo"', $page, 'a normal logo with no dark one gets a white backing in dark mode');
        $this->assertStringContainsString('We are updating', $page);

        // With a dark logo as well, both are in the page and the colour scheme picks one.
        \Illuminate\Support\Facades\Storage::disk(config('thryve.private_disk'))->put('branding/logo_dark.png', UploadedFile::fake()->image('d.png', 80, 30)->getContent());
        $page = $this->device(null)->get('/')->assertStatus(503)->getContent();
        $this->assertStringContainsString('<img class="logo lt " src="/branding/logo"', $page);
        $this->assertStringContainsString('<img class="logo dk" src="/branding/logo_dark"', $page);
    }

    public function test_a_manual_backup_is_refused_while_an_update_runs(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $token = $this->stageAndApprove($c);

        $this->asSuper($c, $token)->postJson('/api/system/backups')->assertStatus(409)->assertJsonPath('error.code', 'busy');
    }

    public function test_the_health_view_reports_backups_and_their_age(): void
    {
        $c = $this->cookieFor(Role::SUPER_ADMIN);
        $get = fn () => collect($this->device($c)->getJson('/api/system/health')->json('data'))->keyBy('key')['backups'];

        $this->assertSame('warn', $get()['status'], 'no backup yet');

        $meta = app(BackupService::class)->create('daily', 'scheduled');
        $this->assertSame('warn', $get()['status'], 'a backup exists but there is no off-server copy');
        $this->assertStringContainsString('only kept on this server', $get()['detail']);

        $meta['created_at'] = now()->subDays(3)->toIso8601String();
        file_put_contents($this->sbx.'/backups/'.$meta['id'].'/meta.json', json_encode($meta));
        $this->assertSame('fail', $get()['status'], 'older than a day');

        \Illuminate\Support\Facades\Storage::fake('offsite');
        config(['thryve.backups.offsite_disk' => 'offsite']);
        $fresh = app(BackupService::class)->create('daily 2', 'scheduled');
        app(BackupService::class)->copyOffsite($fresh);
        $this->assertSame('ok', $get()['status']);
    }
}
