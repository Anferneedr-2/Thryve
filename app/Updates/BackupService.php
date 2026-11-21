<?php

namespace App\Updates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Database dumps and file snapshots, with verification and restore. The dump is written with PDO, so it works on
 * shared hosting where mysqldump may not exist. Each backup is a folder: meta.json, db.sql and (optionally) files.zip.
 */
class BackupService
{
    private const SEP = "\n-- @@\n";

    private const END = '-- END OF DUMP';

    public function root(): string
    {
        $p = config('thryve.backups.path');
        if (! is_dir($p)) {
            mkdir($p, 0775, true);
        }

        return rtrim($p, '/\\');
    }

    private function base(): string
    {
        return rtrim(config('thryve.update.base_path'), '/\\');
    }

    /**
     * Create a backup.
     *
     * @param  list<string>  $snapshotFiles  paths relative to the application root to keep a copy of (missing ones are recorded as "will be created")
     * @param  list<string>  $snapshotDirs  folders (relative to the root) to include in full
     * @param  list<string>|null  $tables  limit the database dump to these tables (null = every table)
     * @return array the backup's meta
     */
    public function create(string $label, string $kind, array $snapshotFiles = [], array $snapshotDirs = [], ?array $tables = null): array
    {
        $tables ??= config('thryve.backups.tables');
        $id = now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        $dir = $this->root().'/'.$id;
        mkdir($dir, 0775, true);

        try {
            $counts = $this->dumpDatabase($dir.'/db.sql', $tables);
            $meta = [
                'id' => $id, 'label' => $label, 'kind' => $kind, 'created_at' => now()->toIso8601String(),
                'app_version' => Version::current(),
                'db_sha256' => hash_file('sha256', $dir.'/db.sql'), 'db_bytes' => filesize($dir.'/db.sql'), 'tables' => $counts, 'full' => $tables === null,
                'files_sha256' => null, 'files_count' => 0, 'files_bytes' => 0, 'created_files' => [], 'offsite' => 'none',
            ];
            if ($snapshotFiles || $snapshotDirs) {
                $meta = array_merge($meta, $this->snapshot($dir.'/files.zip', $snapshotFiles, $snapshotDirs));
            }
            $this->writeMeta($dir, $meta);

            return $meta;
        } catch (\Throwable $e) {
            $this->deleteDir($dir); // never leave a half-written backup that could be mistaken for a good one
            throw $e;
        }
    }

    // ---- database ------------------------------------------------------------------------------

    /** @return array<string, int> table => row count at dump time */
    private function dumpDatabase(string $file, ?array $only): array
    {
        $pdo = DB::connection()->getPdo();
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        if ($only !== null) {
            $tables = array_values(array_intersect($tables, $only));
        }
        $out = fopen($file, 'wb');
        $w = fn (string $sql) => fwrite($out, $sql.self::SEP);
        $w('-- Thryve database dump '.now()->toIso8601String());
        $w('SET FOREIGN_KEY_CHECKS=0');
        $w('SET NAMES utf8mb4');
        $counts = [];

        foreach ($tables as $t) {
            $q = '`'.str_replace('`', '``', $t).'`';
            $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
            $w("DROP TABLE IF EXISTS {$q}");
            $w($create);
            $cols = array_map(fn ($c) => '`'.str_replace('`', '``', $c).'`', $pdo->query("SHOW COLUMNS FROM {$q}")->fetchAll(PDO::FETCH_COLUMN));
            $n = 0;
            for ($offset = 0; ; $offset += 500) {
                $rows = $pdo->query("SELECT * FROM {$q} LIMIT 500 OFFSET {$offset}")->fetchAll(PDO::FETCH_NUM);
                if (! $rows) {
                    break;
                }
                $values = array_map(fn ($r) => '('.implode(',', array_map(fn ($v) => $this->literal($pdo, $v), $r)).')', $rows);
                $w("INSERT INTO {$q} (".implode(',', $cols).') VALUES '.implode(',', $values));
                $n += count($rows);
            }
            $counts[$t] = $n;
        }
        $w('SET FOREIGN_KEY_CHECKS=1');
        fwrite($out, self::END."\n");
        fclose($out);

        return $counts;
    }

    private function literal(PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        $v = (string) $v;
        // Binary values that are not valid text are written as hex so they survive the round trip.
        return mb_check_encoding($v, 'UTF-8') ? $pdo->quote($v) : '0x'.bin2hex($v);
    }

    public function restoreDatabase(array $meta): void
    {
        $file = $this->root().'/'.$meta['id'].'/db.sql';
        if (! is_file($file) || hash_file('sha256', $file) !== $meta['db_sha256']) {
            throw new RuntimeException('The database backup is missing or damaged.');
        }
        $pdo = DB::connection()->getPdo();

        // A complete backup describes the whole database, so tables created since (for example by a migration that
        // failed half way) are removed too; otherwise the restore would leave leftovers that block the next attempt.
        // Backups limited to some tables never remove anything.
        if ($meta['full'] ?? false) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (! array_key_exists($t, $meta['tables'])) {
                    $pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '``', $t).'`');
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        foreach (explode(self::SEP, (string) file_get_contents($file)) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    // ---- files ---------------------------------------------------------------------------------

    protected function snapshot(string $zipPath, array $files, array $dirs): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The file backup could not be created.');
        }
        $base = $this->base();
        $created = [];
        $count = 0;

        $add = function (string $rel) use ($zip, $base, &$count) {
            $zip->addFile($base.'/'.$rel, $rel);
            $count++;
        };
        foreach ($files as $rel) {
            is_file($base.'/'.$rel) ? $add($rel) : $created[] = $rel;
        }
        foreach ($dirs as $d) {
            if (! is_dir($base.'/'.$d)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/'.$d, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && ! $f->isLink()) {
                    $add(str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1)));
                }
            }
        }
        if (! $zip->close() && $count > 0) {
            throw new RuntimeException('The file backup could not be written.');
        }
        if (! is_file($zipPath)) { // ZipArchive writes nothing for an empty archive
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('.empty', '');
            $zip->close();
        }

        return ['files_sha256' => hash_file('sha256', $zipPath), 'files_count' => $count, 'files_bytes' => filesize($zipPath), 'created_files' => $created];
    }

    public function restoreFiles(array $meta): void
    {
        $zipPath = $this->root().'/'.$meta['id'].'/files.zip';
        if (! is_file($zipPath)) {
            return; // this backup holds no files
        }
        if (hash_file('sha256', $zipPath) !== $meta['files_sha256']) {
            throw new RuntimeException('The file backup is damaged.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('The file backup could not be opened.');
        }
        SafeZip::extract($zip, $this->base(), array_values(array_filter(array_column(SafeZip::inspect($zip), 'name'), fn ($n) => $n !== '.empty')));
        $zip->close();
        // Files the update created did not exist before: remove them.
        foreach ($meta['created_files'] as $rel) {
            $path = $this->base().'/'.SafeZip::cleanName($rel);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    // ---- verify, list, prune -------------------------------------------------------------------

    /**
     * Check a backup is complete and readable. With $compareCounts, also check the live row counts still match the
     * dump (only meaningful while nothing is writing, e.g. during maintenance).
     *
     * @return list<string> problems found (empty = good)
     */
    public function verify(array $meta, bool $compareCounts = false): array
    {
        $problems = [];
        $dir = $this->root().'/'.$meta['id'];
        $db = $dir.'/db.sql';

        if (! is_file($db) || filesize($db) === 0) {
            return ['The database backup file is missing.'];
        }
        if (hash_file('sha256', $db) !== $meta['db_sha256']) {
            $problems[] = 'The database backup does not match its checksum.';
        }
        $text = (string) file_get_contents($db);
        if (! str_ends_with(rtrim($text), self::END)) {
            $problems[] = 'The database backup is incomplete.';
        }
        if (substr_count($text, "\nCREATE TABLE ") + (str_starts_with($text, 'CREATE TABLE ') ? 1 : 0) < count($meta['tables'])) {
            $problems[] = 'The database backup is missing tables.';
        }
        if ($compareCounts) {
            foreach ($meta['tables'] as $table => $rows) {
                if ((int) DB::table($table)->count() !== (int) $rows) {
                    $problems[] = "The row count of {$table} changed while backing up.";
                }
            }
        }

        $zipPath = $dir.'/files.zip';
        if ($meta['files_sha256'] !== null) {
            if (! is_file($zipPath) || hash_file('sha256', $zipPath) !== $meta['files_sha256']) {
                $problems[] = 'The file backup is missing or does not match its checksum.';
            } else {
                $zip = new ZipArchive;
                if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
                    $problems[] = 'The file backup cannot be opened.';
                } else {
                    if ($zip->numFiles - ($zip->locateName('.empty') !== false ? 1 : 0) !== (int) $meta['files_count']) {
                        $problems[] = 'The file backup has the wrong number of files.';
                    }
                    $zip->close();
                }
            }
        }

        return $problems;
    }

    public function find(string $id): ?array
    {
        if (! preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id)) {
            return null; // ids are generated by us; anything else could be a path trick
        }
        $f = $this->root().'/'.$id.'/meta.json';

        return is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    }

    /** @return list<array> newest first */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->root().'/*/meta.json') ?: [] as $f) {
            $out[] = json_decode((string) file_get_contents($f), true);
        }
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    public function latest(string $kind = 'scheduled'): ?array
    {
        return collect($this->all())->firstWhere('kind', $kind);
    }

    /**
     * Remove old backups: scheduled ones after `retention_days`, update backups after `update_retention_days`. The backup
     * an unfinished or failed update still needs for its rollback is never removed. Off-server copies follow the same
     * `retention_days`. @return int how many local backups were removed
     */
    public function prune(): int
    {
        $scheduledCutoff = now()->subDays((int) config('thryve.backups.retention_days'))->toIso8601String();
        $updateCutoff = now()->subDays((int) config('thryve.backups.update_retention_days'))->toIso8601String();
        $keep = $this->neededByUpdate();
        $removed = 0;
        foreach ($this->all() as $m) {
            $cutoff = $m['kind'] === 'scheduled' ? $scheduledCutoff : $updateCutoff;
            if ($m['created_at'] < $cutoff && ! in_array($m['id'], $keep, true)) {
                $this->deleteDir($this->root().'/'.$m['id']);
                $removed++;
            }
        }
        $this->pruneOffsite();

        return $removed;
    }

    /** @return list<string> ids of backups a running or failed update still depends on */
    private function neededByUpdate(): array
    {
        $f = rtrim((string) config('thryve.update.dir'), '/\\').'/state.json';
        $state = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;

        return is_array($state) && in_array($state['status'] ?? '', ['running', 'failed'], true) && ! empty($state['backup_id']) ? [$state['backup_id']] : [];
    }

    /** Off-server copies older than the retention window. A failure here never fails the backup run. */
    private function pruneOffsite(): void
    {
        $diskName = config('thryve.backups.offsite_disk');
        if (! $diskName) {
            return;
        }
        $cutoff = now()->subDays((int) config('thryve.backups.retention_days'))->format('Ymd-His');
        try {
            $disk = Storage::disk($diskName);
            foreach ($disk->directories('thryve-backups') as $dir) {
                $id = basename($dir);
                if (preg_match('/^(\d{8}-\d{6})-[0-9a-f]{6}$/', $id, $m) && $m[1] < $cutoff) {
                    $disk->deleteDirectory($dir);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Copy a backup to the off-server disk, if one is configured. @return 'none'|'ok'|'failed' */
    public function copyOffsite(array $meta): string
    {
        $diskName = config('thryve.backups.offsite_disk');
        if (! $diskName) {
            return 'none';
        }
        try {
            $disk = Storage::disk($diskName);
            $dir = $this->root().'/'.$meta['id'];
            // The data first, the description last: a copy that has its meta.json is a complete copy, and that meta.json
            // already says the copy worked (so a restore from the off-server copy shows the right status).
            foreach (['db.sql', 'files.zip'] as $name) {
                if (is_file($dir.'/'.$name)) {
                    $disk->put('thryve-backups/'.$meta['id'].'/'.$name, fopen($dir.'/'.$name, 'rb'));
                }
            }
            $meta['offsite'] = 'ok';
            $this->writeMeta($dir, $meta);
            $disk->put('thryve-backups/'.$meta['id'].'/meta.json', fopen($dir.'/meta.json', 'rb'));
            $status = 'ok';
        } catch (\Throwable $e) {
            report($e);
            $status = 'failed';
        }
        $meta['offsite'] = $status;
        $this->writeMeta($this->root().'/'.$meta['id'], $meta);

        return $status;
    }

    private function writeMeta(string $dir, array $meta): void
    {
        file_put_contents($dir.'/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
