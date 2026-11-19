<?php

namespace Tests\Feature;

use App\Updates\BackupService;
use App\Updates\Maintenance;
use App\Updates\UpdateException;
use App\Updates\UpdateManager;
use App\Updates\UpdateSteps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesUpdateSandbox;
use Tests\Support\RecordingSteps;
use Tests\TestCase;
use ZipArchive;

/** Update flow against a sandbox site. No RefreshDatabase: it only touches its own scratch table. */
class UpdateManagerTest extends TestCase
{
    use UsesUpdateSandbox;

    private RecordingSteps $steps;

    private UpdateManager $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxUp();
        Schema::dropIfExists('upd_scratch');
        Schema::create('upd_scratch', function ($t) {
            $t->id();
            $t->string('note');
        });
        DB::table('upd_scratch')->insert([['note' => 'before-1'], ['note' => 'before-2']]);

        $this->steps = new RecordingSteps;
        $this->app->instance(UpdateSteps::class, $this->steps);
        $this->m = app(UpdateManager::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('upd_scratch');
        $this->sandboxDown();
        parent::tearDown();
    }

    private function stage(string $version = '0.2.0', array $files = [], array $manifest = []): array
    {
        [$zip, $sig] = $this->package($version, $files, $manifest);

        return $this->m->stage($zip, $sig);
    }

    /** Run advance() until the update stops running; returns the sequence of steps taken. */
    private function runToEnd(): array
    {
        $seen = [];
        for ($i = 0; $i < 10 && $this->m->state()['status'] === 'running'; $i++) {
            $seen[] = $this->m->state()['step'];
            $this->m->advance();
        }

        return $seen;
    }

    private function expectRejected(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected the update to be rejected with [{$code}].");
        } catch (UpdateException $e) {
            $this->assertSame($code, $e->codeName, $e->getMessage());
        }
        $this->assertSame('idle', $this->m->state()['status'], 'a rejected package leaves no staged update');
        $this->assertDirectoryDoesNotExist($this->sbx.'/updates/staging');
    }

    // ---- status --------------------------------------------------------------------------------

    public function test_status_of_a_fresh_site(): void
    {
        $s = $this->m->status();

        $this->assertSame('0.1.0', $s['version']);
        $this->assertSame('idle', $s['update']['status']);
        $this->assertTrue($s['key_configured']);
        $this->assertFalse($s['maintenance']);
        $this->assertSame([], $s['history']);
        $this->assertIsArray($s['pending_migrations']);
    }

    public function test_pending_migrations_are_the_real_difference_between_code_and_database(): void
    {
        $this->assertSame([], $this->m->pendingMigrations(), 'a migrated database has nothing pending');

        DB::table('migrations')->where('migration', '2026_11_19_000010_create_notifications_tables')->delete();
        try {
            $this->assertSame(['2026_11_19_000010_create_notifications_tables'], $this->m->pendingMigrations());
        } finally {
            DB::table('migrations')->insert(['migration' => '2026_11_19_000010_create_notifications_tables', 'batch' => (int) DB::table('migrations')->max('batch')]);
        }
    }

    // ---- staging: only signed, safe, newer packages --------------------------------------------

    public function test_a_valid_signed_package_is_staged_and_the_live_site_is_untouched(): void
    {
        $state = $this->stage('0.2.0', ['database/migrations/2099_01_01_000000_future_change.php' => '<?php // new']);

        $this->assertSame('staged', $state['status']);
        $this->assertSame('0.2.0', $state['version']);
        $this->assertSame('0.1.0', $state['from_version']);
        $this->assertEqualsCanonicalizing(['app/Old.php', 'app/NewThing.php', 'config/site.php', 'database/migrations/2099_01_01_000000_future_change.php'], $state['files']);
        $this->assertSame(['2099_01_01_000000_future_change'], $state['pending_migrations']);
        $this->assertSame('Notes for 0.2.0', $state['notes']);
        $this->assertFileExists($this->sbx.'/updates/staging/app/NewThing.php');
        $this->assertSame("<?php // old v0.1.0\n", $this->appFile('app/Old.php'));
        $this->assertNull($this->appFile('app/NewThing.php'));
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));
        $this->assertFalse(app(Maintenance::class)->active());
    }

    public function test_updates_are_refused_without_a_public_key(): void
    {
        [$zip, $sig] = $this->package('0.2.0');
        unlink($this->sbx.'/public.pem');

        $this->expectRejected('key_missing', fn () => $this->m->stage($zip, $sig));
    }

    public function test_a_package_signed_by_someone_else_is_refused(): void
    {
        $stranger = openssl_pkey_get_private(\App\Updates\OpenSsl::newKeyPair(2048)[0]);
        [$zip, $sig] = $this->package('0.2.0', signWith: $stranger);

        $this->expectRejected('bad_signature', fn () => $this->m->stage($zip, $sig));
    }

    public function test_a_package_changed_after_signing_is_refused(): void
    {
        [$zip, $sig] = $this->package('0.2.0');
        $z = new ZipArchive;
        $z->open($zip);
        $z->addFromString('app/Backdoor.php', '<?php system($_GET["c"]);');
        $z->close();

        $this->expectRejected('bad_signature', fn () => $this->m->stage($zip, $sig));
        $this->assertNull($this->appFile('app/Backdoor.php'));
    }

    public function test_a_missing_or_garbage_signature_is_refused(): void
    {
        [$zip, $sig] = $this->package('0.2.0');
        $this->expectRejected('bad_signature', fn () => $this->m->stage($zip, $this->sbx.'/does-not-exist.sig'));

        file_put_contents($sig, 'not base64 !!!');
        $this->expectRejected('bad_signature', fn () => $this->m->stage($zip, $sig));

        file_put_contents($sig, '');
        $this->expectRejected('bad_signature', fn () => $this->m->stage($zip, $sig));
    }

    public function test_only_newer_versions_are_accepted(): void
    {
        $this->expectRejected('not_newer', fn () => $this->stage('0.1.0'));
        $this->expectRejected('not_newer', fn () => $this->stage('0.0.9'));
        $this->expectRejected('bad_package', fn () => $this->stage('1.2', manifest: ['version' => '1.2']));
        $this->expectRejected('bad_package', fn () => $this->stage('1.0.0', manifest: ['version' => 'latest']));
    }

    public function test_the_php_requirement_is_checked(): void
    {
        $this->expectRejected('php_too_old', fn () => $this->stage('0.2.0', manifest: ['requires_php' => '99.0.0']));
    }

    public function test_a_package_without_a_manifest_or_files_is_refused(): void
    {
        $zip = $this->sbx.'/bare.zip';
        $z = new ZipArchive;
        $z->open($zip, ZipArchive::CREATE);
        $z->addFromString('app/Old.php', 'x');
        $z->close();
        openssl_sign('thryve-release:'.hash_file('sha256', $zip), $s, $this->privateKey, OPENSSL_ALGO_SHA256);
        file_put_contents($zip.'.sig', base64_encode($s));
        $this->expectRejected('bad_package', fn () => $this->m->stage($zip, $zip.'.sig'));

        file_put_contents($this->sbx.'/notzip.zip', 'this is not a zip');
        openssl_sign('thryve-release:'.hash_file('sha256', $this->sbx.'/notzip.zip'), $s2, $this->privateKey, OPENSSL_ALGO_SHA256);
        file_put_contents($this->sbx.'/notzip.zip.sig', base64_encode($s2));
        $this->expectRejected('bad_package', fn () => $this->m->stage($this->sbx.'/notzip.zip', $this->sbx.'/notzip.zip.sig'));
    }

    #[DataProvider('dangerousPaths')]
    public function test_dangerous_package_contents_are_refused(string $path, string $code): void
    {
        $this->expectRejected($code, fn () => $this->stage('0.2.0', [$path => 'x']));
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));
        $this->assertSame("APP_KEY=base64:keep-me\nDB_PASSWORD=keep-me-too\n", $this->appFile('.env'));
    }

    public static function dangerousPaths(): array
    {
        return [
            'environment file' => ['.env', 'forbidden_path'],
            'storage folder' => ['storage/app/private/files/secret.txt', 'forbidden_path'],
            'tests folder' => ['tests/Evil.php', 'forbidden_path'],
            'home folder dotfile' => ['.ssh/authorized_keys', 'forbidden_path'],
            'public storage link' => ['public/storage/x.php', 'forbidden_path'],
            'bootstrap cache' => ['bootstrap/cache/config.php', 'forbidden_path'],
            'public hot file' => ['public/hot', 'forbidden_path'],
            'parent traversal' => ['app/../../evil.php', 'unsafe_package'],
            'deep traversal' => ['../../../etc/passwd', 'unsafe_package'],
            'absolute path' => ['/etc/cron.d/evil', 'unsafe_package'],
            'windows drive' => ['C:/Windows/evil.php', 'unsafe_package'],
            'backslash trick' => ['app\\..\\..\\evil.php', 'unsafe_package'],
        ];
    }

    public function test_symbolic_links_inside_a_package_are_refused(): void
    {
        $zipPath = $this->sbx.'/link.zip';
        $z = new ZipArchive;
        $z->open($zipPath, ZipArchive::CREATE);
        $z->addFromString('release.json', json_encode(['version' => '0.2.0']));
        $z->addFromString('app/link', '/etc/passwd');
        $z->setExternalAttributesName('app/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $z->close();
        openssl_sign('thryve-release:'.hash_file('sha256', $zipPath), $s, $this->privateKey, OPENSSL_ALGO_SHA256);
        file_put_contents($zipPath.'.sig', base64_encode($s));

        $this->expectRejected('unsafe_package', fn () => $this->m->stage($zipPath, $zipPath.'.sig'));
    }

    public function test_a_staged_package_can_be_discarded_and_staging_replaced(): void
    {
        $this->stage('0.2.0');
        $this->m->discard();
        $this->assertSame('idle', $this->m->state()['status']);
        $this->assertDirectoryDoesNotExist($this->sbx.'/updates/staging');

        $this->stage('0.2.0');
        $this->stage('0.3.0');
        $this->assertSame('0.3.0', $this->m->state()['version'], 'staging again replaces the earlier package');
    }

    // ---- approve -------------------------------------------------------------------------------

    public function test_approval_needs_a_staged_update_and_the_exact_version(): void
    {
        try {
            $this->m->approve('0.2.0');
            $this->fail('approve without a staged update');
        } catch (UpdateException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->stage('0.2.0');
        try {
            $this->m->approve('0.2.1');
            $this->fail('approve with the wrong version');
        } catch (UpdateException $e) {
            $this->assertSame('confirm_mismatch', $e->codeName);
        }
        $this->assertFalse(app(Maintenance::class)->active(), 'a refused approval does not start maintenance');

        $token = $this->m->approve('0.2.0');
        $this->assertNotEmpty($token);
        $this->assertTrue(app(Maintenance::class)->active());
        $this->assertSame('running', $this->m->state()['status']);
    }

    public function test_only_one_update_step_runs_at_a_time(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $h = fopen($this->sbx.'/updates/lock', 'c');
        flock($h, LOCK_EX);
        try {
            $this->m->advance();
            $this->fail('advance ran while another step held the lock');
        } catch (UpdateException $e) {
            $this->assertSame('busy', $e->codeName);
            $this->assertSame(409, $e->status);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
        $this->assertSame('backup', $this->m->state()['step'], 'the refused call changed nothing');
    }

    // ---- the whole update ----------------------------------------------------------------------

    public function test_a_full_update_runs_one_step_at_a_time_and_finishes_cleanly(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');

        $seen = $this->runToEnd();

        $this->assertSame(['backup', 'verify', 'files', 'migrate', 'seed', 'finalize'], $seen);
        $this->assertSame(['migrate', 'seed', 'caches'], $this->steps->calls);
        $state = $this->m->state();
        $this->assertSame('done', $state['status']);
        $this->assertSame("<?php // new v0.2.0\n", $this->appFile('app/Old.php'));
        $this->assertSame("<?php // added in 0.2.0\n", $this->appFile('app/NewThing.php'));
        $this->assertSame("0.2.0\n", $this->appFile('VERSION'));
        $this->assertSame('0.2.0', $this->m->status()['version']);
        $this->assertSame("APP_KEY=base64:keep-me\nDB_PASSWORD=keep-me-too\n", $this->appFile('.env'), 'secrets are never touched');
        $this->assertFalse(app(Maintenance::class)->active());
        $this->assertDirectoryDoesNotExist($this->sbx.'/updates/staging');
        $this->assertSame('applied', $this->m->history()[0]['result']);
        $this->assertSame('0.2.0', $this->m->history()[0]['version']);
    }

    public function test_the_backup_taken_before_the_update_holds_the_old_files_and_database(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $this->m->advance(); // backup

        $meta = app(BackupService::class)->find($this->m->state()['backup_id']);

        $this->assertSame('update', $meta['kind']);
        $this->assertSame(['upd_scratch' => 2], $meta['tables']);
        $this->assertContains('app/NewThing.php', $meta['created_files'], 'files the update will add are noted so they can be removed');
        $this->assertSame([], app(BackupService::class)->verify($meta));
        $zip = new ZipArchive;
        $zip->open($this->sbx.'/backups/'.$meta['id'].'/files.zip');
        $this->assertSame("<?php // old v0.1.0\n", $zip->getFromName('app/Old.php'));
        $this->assertNotFalse($zip->locateName('VERSION'));
    }

    public function test_update_history_survives_in_files_not_the_database(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $this->runToEnd();

        $fresh = app()->make(UpdateManager::class); // a new instance reads the same files
        $this->assertSame('0.2.0', $fresh->history()[0]['version']);
        $this->assertFileExists($this->sbx.'/updates/history.json');
    }

    // ---- failures ------------------------------------------------------------------------------

    public function test_a_failed_backup_cancels_the_update_before_anything_changes(): void
    {
        $this->stage('0.2.0');
        $broken = new class extends BackupService
        {
            public function create(string $label, string $kind, array $snapshotFiles = [], array $snapshotDirs = [], ?array $tables = null): array
            {
                throw new \RuntimeException('disk is full');
            }
        };
        $this->app->instance(BackupService::class, $broken);
        $m = app()->make(UpdateManager::class);
        $m->approve('0.2.0');

        $state = $m->advance();

        $this->assertSame('staged', $state['status'], 'back to staged so it can be retried');
        $this->assertFalse(app(Maintenance::class)->active(), 'maintenance is lifted: nothing was changed');
        $this->assertStringContainsString('cancelled before anything changed', $state['error']['message']);
        $this->assertSame([], $this->steps->calls, 'migrations never ran');
        $this->assertSame("<?php // old v0.1.0\n", $this->appFile('app/Old.php'));
        $this->assertSame('cancelled', $m->history()[0]['result']);
        $this->assertSame(['upd_scratch'], array_keys(app(BackupService::class)->find('x') ?? ['upd_scratch' => 1]));
    }

    public function test_a_backup_that_fails_its_check_cancels_the_update(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $this->m->advance(); // backup
        file_put_contents($this->sbx.'/backups/'.$this->m->state()['backup_id'].'/db.sql', 'tampered');

        $state = $this->m->advance(); // verify

        $this->assertSame('staged', $state['status']);
        $this->assertFalse(app(Maintenance::class)->active());
        $this->assertSame([], $this->steps->calls);
        $this->assertSame("<?php // old v0.1.0\n", $this->appFile('app/Old.php'));
        $this->assertSame([], glob($this->sbx.'/backups/*'), 'the bad backup is removed');
    }

    public function test_a_failed_migration_keeps_the_site_in_maintenance_with_a_safe_message(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $this->steps->failMigrate = new \RuntimeException("SQLSTATE[42S01]: Base table exists in {$this->site}/database/migrations/x.php with password=hunter2");

        $this->runToEnd();

        $state = $this->m->state();
        $this->assertSame('failed', $state['status']);
        $this->assertSame('migrate', $state['failed_step']);
        $this->assertTrue(app(Maintenance::class)->active(), 'the site stays in maintenance after a failure');
        $this->assertStringNotContainsString($this->site, $state['error']['message'], 'no server paths in the message');
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'), 'the version only changes when everything succeeded');
        $this->assertSame('failed', $this->m->history()[0]['result']);
        try {
            $this->m->advance();
            $this->fail('a failed update cannot simply continue');
        } catch (UpdateException $e) {
            $this->assertSame(409, $e->status);
        }
        try {
            $this->m->stage('x', 'y');
        } catch (UpdateException $e) {
            $this->assertSame('busy', $e->codeName, 'no new package can be staged over a failed update');
        }
    }

    // ---- rollback ------------------------------------------------------------------------------

    public function test_rollback_after_a_failed_update_restores_files_database_and_version(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        // The migration changes data, then fails part-way.
        $this->steps->onMigrate = fn () => DB::table('upd_scratch')->truncate() + DB::table('upd_scratch')->insert(['note' => 'half-migrated']);
        $this->steps->failMigrate = new \RuntimeException('boom');
        $this->runToEnd();
        $this->assertSame("<?php // new v0.2.0\n", $this->appFile('app/Old.php'), 'new files were already in place');
        $this->assertSame(['half-migrated'], DB::table('upd_scratch')->pluck('note')->all());

        try {
            $this->m->rollback('yes please');
            $this->fail('rollback without the typed confirmation');
        } catch (UpdateException $e) {
            $this->assertSame('confirm_mismatch', $e->codeName);
        }
        $this->assertSame(['half-migrated'], DB::table('upd_scratch')->pluck('note')->all(), 'a refused rollback changes nothing');

        $state = $this->m->rollback('rollback');

        $this->assertSame('rolled_back', $state['status']);
        $this->assertSame("<?php // old v0.1.0\n", $this->appFile('app/Old.php'));
        $this->assertSame("<?php return ['v' => '0.1.0'];\n", $this->appFile('config/site.php'));
        $this->assertNull($this->appFile('app/NewThing.php'), 'files the update added are removed');
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));
        $this->assertSame(['before-1', 'before-2'], DB::table('upd_scratch')->orderBy('id')->pluck('note')->all());
        $this->assertFalse(app(Maintenance::class)->active());
        $this->assertSame("APP_KEY=base64:keep-me\nDB_PASSWORD=keep-me-too\n", $this->appFile('.env'));
        $this->assertSame('rolled_back', $this->m->history()[0]['result']);
        $this->assertContains('caches', $this->steps->calls);
        try {
            $this->m->rollback('rollback');
            $this->fail('rolling back twice');
        } catch (UpdateException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_a_failure_while_writing_files_can_also_be_rolled_back(): void
    {
        $this->stage('0.2.0', ['app/Zzz.php' => '<?php // last']);
        // Block one destination with a directory so the copy fails part-way through.
        mkdir($this->site.'/app/Zzz.php', 0775, true);
        $this->m->approve('0.2.0');

        $this->runToEnd();
        $state = $this->m->state();

        $this->assertSame('failed', $state['status']);
        $this->assertSame('files', $state['failed_step']);
        rmdir($this->site.'/app/Zzz.php');

        $this->m->rollback('rollback');
        $this->assertSame("<?php // old v0.1.0\n", $this->appFile('app/Old.php'));
        $this->assertNull($this->appFile('app/NewThing.php'));
        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));
    }

    public function test_a_finished_update_can_be_rolled_back_by_the_super_admin(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $this->runToEnd();
        DB::table('upd_scratch')->insert(['note' => 'written after the update']);

        $this->m->rollback('rollback');

        $this->assertSame("0.1.0\n", $this->appFile('VERSION'));
        $this->assertSame(['before-1', 'before-2'], DB::table('upd_scratch')->orderBy('id')->pluck('note')->all(), 'data written after the update is lost: this is why rollback needs explicit approval');
    }

    public function test_rollback_refuses_when_the_backup_is_missing_or_damaged(): void
    {
        $this->stage('0.2.0');
        $this->m->approve('0.2.0');
        $this->steps->failMigrate = new \RuntimeException('boom');
        $this->runToEnd();
        $id = $this->m->state()['backup_id'];

        file_put_contents($this->sbx.'/backups/'.$id.'/files.zip', 'corrupt');
        try {
            $this->m->rollback('rollback');
            $this->fail('rollback from a damaged backup');
        } catch (UpdateException $e) {
            $this->assertSame('backup_damaged', $e->codeName);
        }
        $this->assertSame("<?php // new v0.2.0\n", $this->appFile('app/Old.php'), 'nothing was changed');
        $this->assertTrue(app(Maintenance::class)->active());

        app(BackupService::class)->deleteDir($this->sbx.'/backups/'.$id);
        try {
            $this->m->rollback('rollback');
            $this->fail('rollback with no backup');
        } catch (UpdateException $e) {
            $this->assertSame('backup_missing', $e->codeName);
        }
    }

    public function test_nothing_to_roll_back_on_an_idle_site(): void
    {
        $this->expectException(UpdateException::class);
        $this->m->rollback('rollback');
    }

    // ---- maintenance flag ----------------------------------------------------------------------

    public function test_the_maintenance_bypass_token_only_matches_the_current_run(): void
    {
        $maintenance = app(Maintenance::class);
        $first = $maintenance->on('first');
        $this->assertTrue($maintenance->allows($first));
        $this->assertFalse($maintenance->allows('guess'));
        $this->assertFalse($maintenance->allows(null));

        $maintenance->off();
        $this->assertFalse($maintenance->allows($first), 'no bypass when maintenance is off');
        $second = $maintenance->on('second');
        $this->assertNotSame($first, $second);
        $this->assertFalse($maintenance->allows($first), 'an old token does not work in a later run');
        $maintenance->off();
    }
}
