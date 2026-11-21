<?php

namespace Tests\Feature;

use App\Updates\BackupService;
use App\Updates\Maintenance;
use App\Updates\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\UsesUpdateSandbox;
use Tests\TestCase;
use ZipArchive;

/** Backups, restore, verification, pruning, the scheduled command and the release tools. Uses only a scratch table. */
class BackupTest extends TestCase
{
    use UsesUpdateSandbox;

    private BackupService $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandboxUp();
        Schema::dropIfExists('upd_scratch');
        Schema::create('upd_scratch', function ($t) {
            $t->id();
            $t->string('note')->nullable();
            $t->longText('body')->nullable();
            $t->binary('blob')->nullable();
            $t->json('doc')->nullable();
        });
        $this->b = app(BackupService::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('upd_scratch');
        $this->sandboxDown();
        parent::tearDown();
    }

    private function seedRows(): void
    {
        DB::table('upd_scratch')->insert([
            ['note' => "O'Brien \"quoted\" \\ back\\slash", 'body' => "line1\nline2; DROP TABLE x; -- @@\nline3", 'blob' => "\x00\xff\xfe\x01binary", 'doc' => json_encode(['a' => [1, 2], 'b' => 'ü ñ 日本語'])],
            ['note' => null, 'body' => null, 'blob' => null, 'doc' => null],
            ['note' => '', 'body' => str_repeat('x', 100000), 'blob' => '', 'doc' => '{}'],
        ]);
        foreach (range(1, 1200) as $i) { // more than one 500-row page
            DB::table('upd_scratch')->insert(['note' => "row $i"]);
        }
    }

    private function snapshotRows(): array
    {
        return DB::table('upd_scratch')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    // ---- database dump and restore -------------------------------------------------------------

    public function test_restoring_a_complete_backup_removes_tables_created_since(): void
    {
        // A migration that fails half way can leave a new table behind; the restore must take it away too.
        $this->seedRows();
        $before = $this->snapshotRows();
        config(['thryve.backups.tables' => null]); // a complete backup
        $meta = $this->b->create('before the update', 'update');
        $this->assertTrue($meta['full']);

        Schema::create('upd_leftover', fn ($t) => $t->id());
        DB::table('upd_scratch')->truncate();
        $this->b->restoreDatabase($meta);

        $this->assertFalse(Schema::hasTable('upd_leftover'), 'the half-made table is gone');
        $this->assertSame($before, $this->snapshotRows());
    }

    public function test_restoring_a_partial_backup_never_removes_other_tables(): void
    {
        $this->seedRows();
        $meta = $this->b->create('only the scratch table', 'scheduled', [], [], ['upd_scratch']);
        $this->assertFalse($meta['full']);

        Schema::create('upd_bystander', fn ($t) => $t->id());
        try {
            $this->b->restoreDatabase($meta);
            $this->assertTrue(Schema::hasTable('upd_bystander'));
        } finally {
            Schema::dropIfExists('upd_bystander');
        }
    }

    public function test_a_dump_restores_every_value_exactly(): void
    {
        $this->seedRows();
        $before = $this->snapshotRows();
        $meta = $this->b->create('test', 'scheduled');

        DB::table('upd_scratch')->truncate();
        DB::table('upd_scratch')->insert(['note' => 'something else']);
        Schema::table('upd_scratch', fn ($t) => $t->dropColumn('doc')); // even a changed structure comes back
        $this->b->restoreDatabase($meta);

        $this->assertSame($before, $this->snapshotRows(), 'quotes, newlines, NULLs, empty strings, binary, JSON and big values survive');
        $this->assertTrue(Schema::hasColumn('upd_scratch', 'doc'));
        $this->assertSame(['upd_scratch' => 1203], $meta['tables']);
    }

    public function test_the_dump_header_covers_encoding_and_foreign_keys_and_ends_with_a_marker(): void
    {
        $meta = $this->b->create('test', 'scheduled');
        $sql = file_get_contents($this->sbx.'/backups/'.$meta['id'].'/db.sql');

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $sql);
        $this->assertStringContainsString('SET NAMES utf8mb4', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `upd_scratch`', $sql);
        $this->assertStringEndsWith("-- END OF DUMP\n", $sql);
    }

    public function test_a_real_dump_of_every_table_verifies_including_row_counts(): void
    {
        config(['thryve.backups.tables' => null]); // the real thing: all tables, read-only
        $meta = $this->b->create('everything', 'scheduled');

        $this->assertGreaterThan(10, count($meta['tables']));
        $this->assertArrayHasKey('users', $meta['tables']);
        $this->assertArrayHasKey('audit_entries', $meta['tables']);
        $this->assertSame([], $this->b->verify($meta, compareCounts: true));
    }

    // ---- verification catches damage -----------------------------------------------------------

    public function test_verify_accepts_a_good_backup_and_catches_every_kind_of_damage(): void
    {
        $this->seedRows();
        mkdir($this->site.'/storage/app/private/files', 0775, true);
        file_put_contents($this->site.'/storage/app/private/files/a.txt', 'hello');
        $meta = $this->b->create('test', 'scheduled', [], ['storage/app/private/files']);
        $dir = $this->sbx.'/backups/'.$meta['id'];
        $this->assertSame([], $this->b->verify($meta, compareCounts: true));

        // Row counts changed since the dump
        DB::table('upd_scratch')->insert(['note' => 'late']);
        $this->assertStringContainsString('row count of upd_scratch changed', implode(' ', $this->b->verify($meta, compareCounts: true)));
        $this->assertSame([], $this->b->verify($meta), 'counts are only compared when asked');

        // Truncated dump
        $sql = file_get_contents($dir.'/db.sql');
        file_put_contents($dir.'/db.sql', substr($sql, 0, -40));
        $problems = implode(' ', $this->b->verify($meta));
        $this->assertStringContainsString('checksum', $problems);
        $this->assertStringContainsString('incomplete', $problems);

        // Changed but same length is still caught by the checksum
        file_put_contents($dir.'/db.sql', str_replace('row 5', 'row 6', $sql));
        $this->assertStringContainsString('checksum', implode(' ', $this->b->verify($meta)));

        // Missing dump, missing or corrupt archive
        file_put_contents($dir.'/db.sql', $sql);
        $this->assertSame([], $this->b->verify($meta));
        file_put_contents($dir.'/files.zip', 'corrupt');
        $this->assertStringContainsString('file backup', implode(' ', $this->b->verify($meta)));
        unlink($dir.'/files.zip');
        $this->assertStringContainsString('file backup', implode(' ', $this->b->verify($meta)));
        unlink($dir.'/db.sql');
        $this->assertSame(['The database backup file is missing.'], $this->b->verify($meta));
    }

    public function test_a_failed_backup_leaves_no_half_written_folder(): void
    {
        $good = $this->b->create('good', 'scheduled');
        $broken = new class extends BackupService
        {
            protected function snapshot(string $zipPath, array $files, array $dirs): array
            {
                throw new \RuntimeException('the file backup could not be written');
            }
        };

        try {
            $broken->create('will fail', 'update', ['app/Old.php']);
            $this->fail('the broken backup should have thrown');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame([$good['id']], array_map('basename', glob($this->sbx.'/backups/*')), 'only the good backup remains; the failed one is removed');
    }

    // ---- files ---------------------------------------------------------------------------------

    public function test_file_snapshots_restore_originals_and_remove_files_that_were_added(): void
    {
        $meta = $this->b->create('files', 'update', ['app/Old.php', 'config/site.php', 'app/WillBeAdded.php', 'VERSION']);
        $this->assertSame(['app/WillBeAdded.php'], $meta['created_files']);
        $this->assertSame(3, $meta['files_count']);

        file_put_contents($this->site.'/app/Old.php', 'changed');
        file_put_contents($this->site.'/app/WillBeAdded.php', 'added by the update');
        unlink($this->site.'/config/site.php');
        $this->b->restoreFiles($meta);

        $this->assertSame("<?php // old v0.1.0\n", $this->appFile('app/Old.php'));
        $this->assertSame("<?php return ['v' => '0.1.0'];\n", $this->appFile('config/site.php'));
        $this->assertNull($this->appFile('app/WillBeAdded.php'));
        $this->assertSame("APP_KEY=base64:keep-me\nDB_PASSWORD=keep-me-too\n", $this->appFile('.env'), 'files outside the snapshot are never touched');
    }

    public function test_restore_cannot_be_tricked_into_deleting_outside_the_site(): void
    {
        $meta = $this->b->create('files', 'update', ['app/Old.php']);
        file_put_contents($this->sbx.'/outside.txt', 'precious');
        $meta['created_files'] = ['../outside.txt'];

        $this->expectException(\RuntimeException::class);
        try {
            $this->b->restoreFiles($meta);
        } finally {
            $this->assertSame('precious', file_get_contents($this->sbx.'/outside.txt'));
        }
    }

    public function test_backup_ids_are_validated_so_they_cannot_be_paths(): void
    {
        $meta = $this->b->create('x', 'scheduled');
        $this->assertNotNull($this->b->find($meta['id']));
        foreach (['../backups', '../../etc/passwd', "{$meta['id']}/../..", '', 'abc', '20260101-000000-zzzzzz'] as $bad) {
            $this->assertNull($this->b->find($bad), $bad);
        }
    }

    // ---- listing, retention, off-server copy ---------------------------------------------------

    private function age(array $m, int $days): array
    {
        $m['created_at'] = now()->subDays($days)->toIso8601String();
        file_put_contents($this->sbx.'/backups/'.$m['id'].'/meta.json', json_encode($m));

        return $m;
    }

    public function test_pruning_follows_the_retention_rules(): void
    {
        // Scheduled backups last 14 days, backups taken before an update last 30 days.
        $oldScheduled = $this->age($this->b->create('old scheduled', 'scheduled'), 20);
        $recentScheduled = $this->age($this->b->create('recent scheduled', 'scheduled'), 10);
        $oldUpdate = $this->age($this->b->create('old update', 'update'), 40);
        $recentUpdate = $this->age($this->b->create('recent update', 'update'), 20);
        $new = $this->b->create('new scheduled', 'scheduled');

        $this->assertSame(2, $this->b->prune());

        $this->assertEqualsCanonicalizing([$recentScheduled['id'], $recentUpdate['id'], $new['id']], array_column($this->b->all(), 'id'));
        $this->assertSame($new['id'], $this->b->latest('scheduled')['id']);
    }

    public function test_the_backup_an_unfinished_update_needs_is_never_pruned(): void
    {
        $needed = $this->age($this->b->create('before a failed update', 'update'), 90);
        $other = $this->age($this->b->create('some other old update', 'update'), 90);
        file_put_contents($this->sbx.'/updates/state.json', json_encode(['status' => 'failed', 'backup_id' => $needed['id']]));

        $this->assertSame(1, $this->b->prune());
        $this->assertSame([$needed['id']], array_column($this->b->all(), 'id'));

        // Once that update is finished (or rolled back) its backup ages out like any other.
        file_put_contents($this->sbx.'/updates/state.json', json_encode(['status' => 'rolled_back', 'backup_id' => $needed['id']]));
        $this->assertSame(1, $this->b->prune());
        $this->assertSame([], $this->b->all());
    }

    public function test_off_server_copies_are_pruned_by_age_too(): void
    {
        Storage::fake('offsite');
        config(['thryve.backups.offsite_disk' => 'offsite']);
        $oldId = now()->subDays(20)->format('Ymd-His').'-aaaaaa';
        $newId = now()->subDays(2)->format('Ymd-His').'-bbbbbb';
        Storage::disk('offsite')->put("thryve-backups/{$oldId}/meta.json", '{}');
        Storage::disk('offsite')->put("thryve-backups/{$newId}/meta.json", '{}');

        $this->b->prune();

        Storage::disk('offsite')->assertMissing("thryve-backups/{$oldId}/meta.json");
        Storage::disk('offsite')->assertExists("thryve-backups/{$newId}/meta.json");
    }

    public function test_an_off_server_copy_is_made_when_a_disk_is_configured(): void
    {
        Storage::fake('offsite');
        config(['thryve.backups.offsite_disk' => 'offsite']);
        $meta = $this->b->create('x', 'scheduled', [], ['app']);

        $this->assertSame('ok', $this->b->copyOffsite($meta));

        Storage::disk('offsite')->assertExists("thryve-backups/{$meta['id']}/db.sql");
        Storage::disk('offsite')->assertExists("thryve-backups/{$meta['id']}/files.zip");
        Storage::disk('offsite')->assertExists("thryve-backups/{$meta['id']}/meta.json");
        $this->assertSame('ok', $this->b->find($meta['id'])['offsite']);
        // The description stored off-server already says the copy worked, so restoring from it shows the right status.
        $remote = json_decode(Storage::disk('offsite')->get("thryve-backups/{$meta['id']}/meta.json"), true);
        $this->assertSame('ok', $remote['offsite']);
    }

    public function test_off_server_copy_is_none_when_unset_and_failed_when_the_disk_is_broken(): void
    {
        $meta = $this->b->create('x', 'scheduled');
        $this->assertSame('none', $this->b->copyOffsite($meta));
        $this->assertSame('none', $this->b->find($meta['id'])['offsite']);

        config(['thryve.backups.offsite_disk' => 'a-disk-that-does-not-exist']);
        $this->assertSame('failed', $this->b->copyOffsite($meta));
        $this->assertSame('failed', $this->b->find($meta['id'])['offsite']);
    }

    // ---- commands ------------------------------------------------------------------------------

    public function test_the_backup_command_makes_a_verified_backup_with_uploaded_files(): void
    {
        mkdir($this->site.'/storage/app/private/files/7', 0775, true);
        file_put_contents($this->site.'/storage/app/private/files/7/report.pdf', 'pdf bytes');
        $this->seedRows();

        $this->artisan('thryve:backup')->assertSuccessful();

        $latest = $this->b->latest('scheduled');
        $this->artisan('thryve:backups')->expectsOutputToContain($latest['id'])->assertSuccessful(); // the id to give thryve:restore
        $this->assertSame('Scheduled backup', $latest['label']);
        $this->assertSame(1, $latest['files_count']);
        $zip = new ZipArchive;
        $zip->open($this->sbx.'/backups/'.$latest['id'].'/files.zip');
        $this->assertSame('pdf bytes', $zip->getFromName('storage/app/private/files/7/report.pdf'));
        $this->assertSame([], $this->b->verify($latest));
    }

    public function test_the_backup_command_pauses_while_an_update_runs(): void
    {
        app(Maintenance::class)->on('updating');

        $this->artisan('thryve:backup')->assertSuccessful();

        $this->assertSame([], $this->b->all());
    }

    public function test_the_restore_command_replaces_data_from_a_backup_and_refuses_bad_ones(): void
    {
        DB::table('upd_scratch')->insert(['note' => 'original']);
        $meta = $this->b->create('x', 'scheduled');
        DB::table('upd_scratch')->update(['note' => 'damaged']);

        $this->artisan('thryve:restore', ['id' => 'nope'])->assertFailed();
        $this->assertSame('damaged', DB::table('upd_scratch')->value('note'));

        $this->artisan('thryve:restore', ['id' => $meta['id'], '--force' => true])->assertSuccessful();
        $this->assertSame('original', DB::table('upd_scratch')->value('note'));

        file_put_contents($this->sbx.'/backups/'.$meta['id'].'/db.sql', 'tampered');
        DB::table('upd_scratch')->update(['note' => 'again damaged']);
        $this->artisan('thryve:restore', ['id' => $meta['id'], '--force' => true])->assertFailed();
        $this->assertSame('again damaged', DB::table('upd_scratch')->value('note'), 'a damaged backup is never applied');
    }

    public function test_the_daily_backup_is_scheduled_at_the_configured_philippine_time(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'thryve:backup'));

        $this->assertCount(1, $events);
        $e = $events->first();
        $this->assertSame('0 2 * * *', $e->expression);
        $this->assertSame('Asia/Manila', (string) $e->timezone);
    }

    public function test_every_scheduled_task_pauses_during_maintenance(): void
    {
        app(Maintenance::class)->on('updating');
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();

        $this->assertNotEmpty($events);
        foreach ($events as $e) {
            $this->assertFalse($e->filtersPass(app()), $e->command.' must not run during an update');
        }
        app(Maintenance::class)->off();
        $this->assertTrue($events[0]->filtersPass(app()));
    }

    // ---- release tools -------------------------------------------------------------------------

    public function test_version_helpers(): void
    {
        $this->assertSame('0.1.0', Version::current($this->site));
        $this->assertTrue(Version::valid('1.20.3'));
        foreach (['1.2', 'v1.2.3', '1.2.3-beta', '', 'latest', '1.2.3.4'] as $bad) {
            $this->assertFalse(Version::valid($bad), $bad);
        }
        $this->assertTrue(Version::isNewer('0.10.0', '0.9.0'), 'compared as numbers, not text');
        $this->assertFalse(Version::isNewer('0.1.0', '0.1.0'));
    }

    public function test_the_release_tools_create_keys_package_and_sign_so_the_server_accepts_it(): void
    {
        $keys = $this->sbx.'/keys';
        mkdir($keys);
        $this->artisan('thryve:release', ['action' => 'keygen', 'a' => $keys])->assertSuccessful();
        $this->assertFileExists($keys.'/thryve-release-private.pem');
        $this->assertStringContainsString('PUBLIC KEY', file_get_contents($keys.'/thryve-release-public.pem'));
        $this->assertStringNotContainsString('PRIVATE', file_get_contents($keys.'/thryve-release-public.pem'));

        $zip = $this->sbx.'/built.zip';
        $this->artisan('thryve:release', ['action' => 'package', 'a' => $zip, '--release' => '9.9.9', '--notes' => 'From the tool'])->assertSuccessful();
        $z = new ZipArchive;
        $z->open($zip);
        $names = array_map(fn ($i) => $z->getNameIndex($i), range(0, $z->numFiles - 1));
        $this->assertContains('release.json', $names);
        $this->assertContains('app/Updates/UpdateManager.php', $names);
        $this->assertContains('routes/api.php', $names);
        foreach ($names as $n) {
            $this->assertDoesNotMatchRegularExpression('#^(\.env|storage/|vendor/|tests/|node_modules/|\.git/)#', $n, "{$n} must not be packaged");
        }
        $this->assertSame('9.9.9', json_decode($z->getFromName('release.json'), true)['version']);

        $this->artisan('thryve:release', ['action' => 'sign', 'a' => $zip, 'b' => $keys.'/thryve-release-private.pem'])->assertSuccessful();
        $this->assertFileExists($zip.'.sig');

        // The server, holding only the public key, accepts it; a different key would not.
        copy($keys.'/thryve-release-public.pem', $this->sbx.'/public.pem');
        $state = app(\App\Updates\UpdateManager::class)->stage($zip, $zip.'.sig');
        $this->assertSame('9.9.9', $state['version']);
        $this->assertSame('From the tool', $state['notes']);
        $this->assertGreaterThan(50, $state['file_count']);
    }

    public function test_signing_with_the_wrong_or_missing_inputs_fails_cleanly(): void
    {
        $this->artisan('thryve:release', ['action' => 'sign', 'a' => $this->sbx.'/none.zip', 'b' => $this->sbx.'/none.pem'])->assertFailed();
        $this->artisan('thryve:release', ['action' => 'package', 'a' => $this->sbx.'/x.zip', '--release' => 'abc'])->assertFailed();
        $this->artisan('thryve:release', ['action' => 'keygen', 'a' => $this->sbx.'/no-such-folder'])->assertFailed();
        $this->artisan('thryve:release', ['action' => 'bogus'])->assertFailed();
    }
}
