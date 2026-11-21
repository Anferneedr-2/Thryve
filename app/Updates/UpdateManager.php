<?php

namespace App\Updates;

use App\Models\User;
use App\Notifications\Notifier;
use App\Support\Audit;
use RuntimeException;
use ZipArchive;

/**
 * Staged, Super Admin approved updates with a backup first and a way back.
 *
 * Flow: stage (verify signature, inspect, unpack) -> approve (maintenance on) -> backup -> verify backup -> apply files
 * -> migrate -> seed -> finalize (version, caches, maintenance off). Each advance() call performs ONE step, so a long
 * update never depends on a single web request staying open. State lives in files, not the database, so it survives
 * a database restore. If a step after the backup fails, the site stays in maintenance and the only way forward is an
 * approved rollback (files and database restored from the backup). If the backup itself fails, nothing was changed:
 * maintenance is lifted and the update returns to "staged".
 */
class UpdateManager
{
    public const STEPS = ['backup', 'verify', 'files', 'migrate', 'seed', 'finalize'];

    /** A database-only update (code already deployed, for example by a GitHub Action) has no file step. */
    public const DATABASE_STEPS = ['backup', 'verify', 'migrate', 'seed', 'finalize'];

    private function isDatabase(array $state): bool
    {
        return ($state['kind'] ?? '') === 'database';
    }

    public function __construct(private BackupService $backups, private Maintenance $maintenance, private UpdateSteps $steps) {}

    // ---- locations and state -------------------------------------------------------------------

    private function dir(): string
    {
        $d = rtrim(config('thryve.update.dir'), '/\\');
        if (! is_dir($d)) {
            mkdir($d, 0775, true);
        }

        return $d;
    }

    private function base(): string
    {
        return rtrim(config('thryve.update.base_path'), '/\\');
    }

    private function stateFile(): string
    {
        return $this->dir().'/state.json';
    }

    public function state(): array
    {
        $f = $this->stateFile();

        return is_file($f) ? json_decode((string) file_get_contents($f), true) : ['status' => 'idle'];
    }

    private function save(array $state): array
    {
        file_put_contents($this->stateFile(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $state;
    }

    public function history(): array
    {
        $f = $this->dir().'/history.json';

        return is_file($f) ? json_decode((string) file_get_contents($f), true) : [];
    }

    private function record(array $entry): void
    {
        $h = $this->history();
        array_unshift($h, $entry + ['at' => now()->toIso8601String()]);
        file_put_contents($this->dir().'/history.json', json_encode(array_slice($h, 0, 100), JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** One update at a time: a second request while a step is running is refused rather than interleaved. */
    private function locked(callable $fn): mixed
    {
        $h = fopen($this->dir().'/lock', 'c');
        if (! flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            throw new UpdateException('busy', 'An update step is already running. Wait for it to finish.', 409);
        }
        try {
            return $fn();
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    // ---- status --------------------------------------------------------------------------------

    /** Names of migrations in the installed code that the database has not run yet. @return list<string> */
    public function pendingMigrations(): array
    {
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));

        return array_values(array_diff($files, $migrator->getRepository()->getRan()));
    }

    /** True when the deployed code has database changes that have not been applied yet. Cheap enough to ask on every request. */
    public function databaseBehind(): bool
    {
        try {
            return $this->pendingMigrations() !== [];
        } catch (\Throwable) {
            return false; // no database yet (installer) or unreadable: never lock people out because of this check
        }
    }

    public function status(): array
    {
        $state = $this->state();
        unset($state['secret']);

        return [
            'version' => Version::current($this->base()),
            'php' => PHP_VERSION,
            'update' => $state,
            'pending_migrations' => $pending = $this->pendingMigrations(),
            'database_behind' => $pending !== [],
            'maintenance' => $this->maintenance->active(),
            'key_configured' => is_file(config('thryve.update.public_key')),
            'history' => $this->history(),
        ];
    }

    // ---- stage ---------------------------------------------------------------------------------

    /** Verify the signature, inspect the package and unpack it for review. Nothing in the live site changes. */
    public function stage(string $zipPath, string $signaturePath): array
    {
        $state = $this->state();
        if (in_array($state['status'], ['running', 'failed'], true)) {
            throw new UpdateException('busy', 'Finish or roll back the current update first.', 409);
        }
        $this->verifySignature($zipPath, $signaturePath);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new UpdateException('bad_package', 'The package is not a valid zip file.');
        }
        try {
            $entries = SafeZip::inspect($zip);
        } catch (RuntimeException $e) {
            throw new UpdateException('unsafe_package', $e->getMessage());
        }

        $manifest = json_decode((string) $zip->getFromName('release.json'), true);
        if (! is_array($manifest) || ! Version::valid($manifest['version'] ?? '')) {
            throw new UpdateException('bad_package', 'The package has no valid release.json with a version like 1.2.3.');
        }
        $installed = Version::current($this->base());
        if (! Version::isNewer($manifest['version'], $installed)) {
            throw new UpdateException('not_newer', "Version {$manifest['version']} is not newer than the installed version {$installed}.");
        }
        if (isset($manifest['requires_php']) && version_compare(PHP_VERSION, $manifest['requires_php'], '<')) {
            throw new UpdateException('php_too_old', "This release needs PHP {$manifest['requires_php']} or newer; the server runs ".PHP_VERSION.'.');
        }

        $files = [];
        foreach ($entries as $e) {
            if ($e['dir'] || $e['name'] === 'release.json') {
                continue;
            }
            $this->assertAllowed($e['name']);
            $files[] = $e['name'];
        }
        if (! $files) {
            throw new UpdateException('bad_package', 'The package contains no files.');
        }

        $stage = $this->dir().'/staging';
        $this->backups->deleteDir($stage);
        mkdir($stage, 0775, true);
        SafeZip::extract($zip, $stage, $files);
        $zip->close();

        $newMigrations = collect($files)->filter(fn ($f) => preg_match('#^database/migrations/[^/]+\.php$#', $f))
            ->map(fn ($f) => basename($f, '.php'))->values();
        $pending = array_values($newMigrations->diff($this->ranMigrations())->all());

        $staged = $this->save([
            'status' => 'staged', 'version' => $manifest['version'], 'from_version' => $installed, 'notes' => mb_substr((string) ($manifest['notes'] ?? ''), 0, 2000),
            'files' => $files, 'file_count' => count($files), 'pending_migrations' => $pending,
            'package_sha256' => hash_file('sha256', $zipPath), 'staged_at' => now()->toIso8601String(), 'step' => null, 'error' => null,
        ]);

        // "Update available": tell the Super Admins a checked package is waiting for their approval (once per package).
        try {
            app(Notifier::class)->notify('system.update', "Update {$manifest['version']} is ready to review",
                'A signed package passed its checks. Open Settings > Updates and approve it when you are ready.',
                ['version' => $manifest['version']], 'update-staged:'.$staged['package_sha256']);
        } catch (\Throwable $e) {
            report($e);
        }

        return $staged;
    }

    private function ranMigrations(): array
    {
        return app('migrator')->getRepository()->getRan();
    }

    private function assertAllowed(string $name): void
    {
        $top = explode('/', $name)[0];
        if (! in_array($top, config('thryve.update.allowed_paths'), true)) {
            throw new UpdateException('forbidden_path', "The package contains a file outside the allowed folders: {$top}.");
        }
        foreach (config('thryve.update.protected_paths') as $p) {
            if ($name === $p || str_starts_with($name, $p.'/')) {
                throw new UpdateException('forbidden_path', "The package may not contain {$p}.");
            }
        }
    }

    /** Only packages signed with the owner's key are accepted (RSA, SHA-256 over the package hash). */
    private function verifySignature(string $zipPath, string $sigPath): void
    {
        $keyFile = config('thryve.update.public_key');
        if (! is_file($keyFile)) {
            throw new UpdateException('key_missing', 'Updates are switched off: no public key is installed to check packages against.', 422);
        }
        $key = openssl_pkey_get_public((string) file_get_contents($keyFile));
        $signature = base64_decode(trim((string) @file_get_contents($sigPath)), true);
        if (! $key || $signature === false || $signature === '') {
            throw new UpdateException('bad_signature', 'The signature file is not valid.', 422);
        }
        $ok = openssl_verify('thryve-release:'.hash_file('sha256', $zipPath), $signature, $key, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new UpdateException('bad_signature', 'This package is not signed by the owner. It was not accepted.', 422);
        }
    }

    public function discard(): void
    {
        $state = $this->state();
        if ($state['status'] !== 'staged') {
            throw new UpdateException('busy', 'There is no staged package to discard.', 409);
        }
        $this->backups->deleteDir($this->dir().'/staging');
        $this->save(['status' => 'idle']);
    }

    // ---- approve and run -----------------------------------------------------------------------

    /** The Super Admin confirms the exact version. Maintenance starts; returns the bypass token for their browser. */
    public function approve(string $confirmVersion): string
    {
        return $this->locked(function () use ($confirmVersion) {
            $state = $this->state();
            if ($state['status'] !== 'staged') {
                throw new UpdateException('busy', 'There is no staged update to approve.', 409);
            }
            if ($confirmVersion !== $state['version']) {
                throw new UpdateException('confirm_mismatch', 'Type the version number to confirm.', 422);
            }
            $token = $this->maintenance->on("Updating to version {$state['version']}. This usually takes a few minutes.");
            $this->save(array_merge($state, ['status' => 'running', 'step' => 'backup', 'started_at' => now()->toIso8601String(), 'error' => null]));

            return $token;
        });
    }

    /**
     * New code is already on the server (deployed by a GitHub Action or similar) and the database is behind it. The Super
     * Admin confirms; maintenance starts and the same safe sequence runs without the file step: backup, check the backup,
     * migrate, seed, finish. Returns the bypass token for the Super Admin's browser.
     */
    public function approveDatabase(string $confirm): string
    {
        return $this->locked(function () use ($confirm) {
            $state = $this->state();
            if (in_array($state['status'], ['running', 'failed', 'staged'], true)) {
                throw new UpdateException('busy', $state['status'] === 'staged' ? 'Discard the staged package first.' : 'Finish or roll back the current update first.', 409);
            }
            $pending = $this->pendingMigrations();
            if (! $pending) {
                throw new UpdateException('nothing_to_do', 'The database is already up to date.', 409);
            }
            if ($confirm !== 'update') {
                throw new UpdateException('confirm_mismatch', 'Confirm to update the database.', 422);
            }
            $version = Version::current($this->base());
            $token = $this->maintenance->on('Updating the database. This usually takes a few minutes.');
            $this->save([
                'status' => 'running', 'kind' => 'database', 'step' => 'backup', 'version' => $version, 'from_version' => $version,
                'notes' => 'Database update: '.count($pending).' change(s).', 'files' => [], 'file_count' => 0, 'pending_migrations' => $pending,
                'started_at' => now()->toIso8601String(), 'error' => null,
            ]);

            return $token;
        });
    }

    /** Run the next step. Returns the new state. */
    public function advance(): array
    {
        return $this->locked(function () {
            $state = $this->state();
            if ($state['status'] !== 'running') {
                throw new UpdateException('busy', 'No update is running.', 409);
            }
            $step = $state['step'];
            try {
                $state = match ($step) {
                    'backup' => $this->stepBackup($state),
                    'verify' => $this->stepVerify($state),
                    'files' => $this->stepFiles($state),
                    'migrate' => $this->stepMigrate($state),
                    'seed' => $this->stepSeed($state),
                    'finalize' => $this->stepFinalize($state),
                };
            } catch (\Throwable $e) {
                report($e);

                return $this->fail($state, $step, $e);
            }

            return $this->save($state);
        });
    }

    private function next(array $state, string $from): array
    {
        $steps = $this->isDatabase($state) ? self::DATABASE_STEPS : self::STEPS;
        $state['step'] = $steps[array_search($from, $steps, true) + 1] ?? null;

        return $state;
    }

    private function stepBackup(array $state): array
    {
        $snapshot = $this->isDatabase($state) ? [] : array_merge($state['files'], ['VERSION']);
        $meta = $this->backups->create($this->isDatabase($state) ? 'Before a database update' : "Before update to {$state['version']}", 'update', $snapshot);
        $state['backup_id'] = $meta['id'];

        return $this->next($state, 'backup');
    }

    private function stepVerify(array $state): array
    {
        $meta = $this->backups->find($state['backup_id']);
        $problems = $meta ? $this->backups->verify($meta, compareCounts: true) : ['The backup could not be found.'];
        if ($problems) {
            throw new UpdateAbort('The backup did not pass its check: '.implode(' ', $problems));
        }

        return $this->next($state, 'verify');
    }

    private function stepFiles(array $state): array
    {
        $stage = $this->dir().'/staging';
        foreach ($state['files'] as $rel) {
            $src = $stage.'/'.SafeZip::cleanName($rel);
            $dst = $this->base().'/'.$rel;
            @mkdir(dirname($dst), 0775, true);
            $tmp = $dst.'.thryve-new';
            if (! @copy($src, $tmp) || ! @rename($tmp, $dst)) {
                @unlink($tmp);
                throw new RuntimeException("A file could not be written: {$rel}.");
            }
        }

        return $this->next($state, 'files');
    }

    private function stepMigrate(array $state): array
    {
        $this->steps->migrate();

        return $this->next($state, 'migrate');
    }

    private function stepSeed(array $state): array
    {
        $this->steps->seed();

        return $this->next($state, 'seed');
    }

    private function stepFinalize(array $state): array
    {
        if (! $this->isDatabase($state)) {
            file_put_contents($this->base().'/VERSION', $state['version']."\n"); // a database update keeps the deployed version
        }
        $this->steps->clearCaches();
        $this->maintenance->off();
        $this->backups->deleteDir($this->dir().'/staging');

        $state['status'] = 'done';
        $state['step'] = null;
        $state['finished_at'] = now()->toIso8601String();
        $database = $this->isDatabase($state);
        $this->record(['version' => $state['version'], 'from' => $state['from_version'], 'result' => 'applied', 'backup_id' => $state['backup_id'], 'kind' => $database ? 'database' : 'package',
            'notes' => $database ? 'Database update: '.implode(', ', $state['pending_migrations'] ?? []) : ($state['notes'] ?? '')]);
        app(Notifier::class)->notify('system.update', $database ? 'The database was updated' : "Updated to version {$state['version']}",
            $database ? count($state['pending_migrations'] ?? []).' database change(s) were applied.' : 'The update finished successfully.', ['version' => $state['version']]);

        return $state;
    }

    /** A step failed. Before any change was made the update is simply cancelled; afterwards the site stays in maintenance. */
    private function fail(array $state, string $step, \Throwable $e): array
    {
        $message = $this->safeMessage($e);

        if (in_array($step, ['backup', 'verify'], true)) {
            if (! empty($state['backup_id'])) {
                $this->backups->deleteDir($this->backups->root().'/'.$state['backup_id']);
            }
            $this->maintenance->off();
            unset($state['backup_id']);
            $state['status'] = $this->isDatabase($state) ? 'idle' : 'staged';
            $state['step'] = null;
            $state['error'] = ['step' => $step, 'message' => "The update was cancelled before anything changed. {$message}"];
            $this->record(['version' => $state['version'], 'from' => $state['from_version'], 'result' => 'cancelled', 'notes' => $message]);

            return $this->save($state);
        }

        $state['status'] = 'failed';
        $state['failed_step'] = $step;
        $state['error'] = ['step' => $step, 'message' => $message];
        $this->record(['version' => $state['version'], 'from' => $state['from_version'], 'result' => 'failed', 'backup_id' => $state['backup_id'] ?? null, 'notes' => "Failed at: {$step}"]);
        // The site stays in maintenance. Tell the Super Admins (the notification table may be unusable, so never let this throw).
        try {
            app(Notifier::class)->notify('system.update', "Update to {$state['version']} failed", "Step: {$step}. The site is in maintenance. Open System > Updates to roll back.", ['version' => $state['version']]);
        } catch (\Throwable) {
        }

        return $this->save($state);
    }

    /** Short, human text without file paths or credentials. */
    private function safeMessage(\Throwable $e): string
    {
        if ($e instanceof UpdateAbort) {
            return $e->getMessage();
        }
        $m = str_replace([$this->base(), base_path(), $this->dir()], '[app]', $e->getMessage());

        return mb_substr(preg_replace('/\s+/', ' ', strip_tags($m)), 0, 200);
    }

    // ---- rollback ------------------------------------------------------------------------------

    /** Put the previous version back: files first, then the database. Destructive, so it needs explicit confirmation. */
    public function rollback(string $confirm, ?User $by = null): array
    {
        return $this->locked(function () use ($confirm, $by) {
            $state = $this->state();
            if (! in_array($state['status'], ['failed', 'done'], true) || empty($state['backup_id'])) {
                throw new UpdateException('busy', 'There is no update to roll back.', 409);
            }
            if ($confirm !== 'rollback') {
                throw new UpdateException('confirm_mismatch', 'Type "rollback" to confirm.', 422);
            }
            $meta = $this->backups->find($state['backup_id']);
            if (! $meta) {
                throw new UpdateException('backup_missing', 'The backup for this update is missing, so it cannot be rolled back.', 422);
            }
            if ($problems = $this->backups->verify($meta)) {
                throw new UpdateException('backup_damaged', 'The backup did not pass its check: '.implode(' ', $problems), 422);
            }

            $token = $this->maintenance->active() ? null : $this->maintenance->on('Restoring the previous version. This usually takes a few minutes.');
            try {
                $this->backups->restoreFiles($meta);
                $this->backups->restoreDatabase($meta);
                $this->steps->clearCaches();
            } catch (\Throwable $e) {
                report($e);
                $state['error'] = ['step' => 'rollback', 'message' => 'The rollback did not finish: '.$this->safeMessage($e).' The site is still in maintenance.'];

                return $this->save($state);
            }
            $this->maintenance->off();
            $this->backups->deleteDir($this->dir().'/staging');

            $state['status'] = 'rolled_back';
            $state['error'] = null;
            $state['step'] = null;
            $this->record(['version' => $state['version'], 'from' => $state['from_version'], 'result' => 'rolled_back', 'backup_id' => $state['backup_id'], 'notes' => $this->isDatabase($state) ? 'Restored the database from the backup' : 'Restored version '.$state['from_version']]);
            $this->save($state);

            // Written after the restore so it survives it.
            if ($by) {
                try {
                    Audit::log('system.update_rolled_back', $by, null, ['version' => $state['version'], 'restored' => $state['from_version']]);
                } catch (\Throwable) {
                }
            }

            return $state;
        });
    }
}
