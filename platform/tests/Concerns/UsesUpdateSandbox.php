<?php

namespace Tests\Concerns;

use App\Updates\Maintenance;
use App\Updates\OpenSsl;
use ZipArchive;

/**
 * A throwaway "installed site" for update and backup tests: its own application folder, update folder, backup folder
 * and signing keys. Nothing here touches the real project files or the shared test database tables.
 */
trait UsesUpdateSandbox
{
    protected string $sbx;

    protected string $site;

    protected mixed $privateKey;

    protected function sandboxUp(): void
    {
        $this->sbx = sys_get_temp_dir().'/thryve-sbx-'.bin2hex(random_bytes(6));
        $this->site = $this->sbx.'/app';
        mkdir($this->site.'/app', 0775, true);
        mkdir($this->site.'/config', 0775, true);
        mkdir($this->sbx.'/updates', 0775, true);
        mkdir($this->sbx.'/backups', 0775, true);

        file_put_contents($this->site.'/VERSION', "0.1.0\n");
        file_put_contents($this->site.'/app/Old.php', "<?php // old v0.1.0\n");
        file_put_contents($this->site.'/config/site.php', "<?php return ['v' => '0.1.0'];\n");
        file_put_contents($this->site.'/.env', "APP_KEY=base64:keep-me\nDB_PASSWORD=keep-me-too\n");

        [$private, $public] = OpenSsl::newKeyPair(2048);
        $this->privateKey = openssl_pkey_get_private($private);
        file_put_contents($this->sbx.'/public.pem', $public);

        config([
            'thryve.update.base_path' => $this->site,
            'thryve.update.dir' => $this->sbx.'/updates',
            'thryve.update.public_key' => $this->sbx.'/public.pem',
            'thryve.backups.path' => $this->sbx.'/backups',
            'thryve.backups.tables' => ['upd_scratch'], // never dump or restore the shared test tables
            'thryve.backups.offsite_disk' => null,
        ]);
        app(Maintenance::class)->off();
    }

    protected function sandboxDown(): void
    {
        app(Maintenance::class)->off();
        $this->rmTree($this->sbx);
    }

    protected function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Build a release package and its signature.
     *
     * @param  array<string, string>  $files  path inside the zip => contents
     * @return array{0: string, 1: string} zip path, signature path
     */
    protected function package(string $version, array $files = [], array $manifest = [], bool $sign = true, mixed $signWith = null, ?string $name = null): array
    {
        $zipPath = $this->sbx.'/'.($name ?? "release-{$version}-".bin2hex(random_bytes(2)).'.zip');
        $files += ['app/Old.php' => "<?php // new v{$version}\n", 'config/site.php' => "<?php return ['v' => '{$version}'];\n", 'app/NewThing.php' => "<?php // added in {$version}\n"];

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('release.json', json_encode($manifest + ['version' => $version, 'notes' => "Notes for {$version}"]));
        foreach ($files as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        $sigPath = $zipPath.'.sig';
        if ($sign) {
            openssl_sign('thryve-release:'.hash_file('sha256', $zipPath), $sig, $signWith ?? $this->privateKey, OPENSSL_ALGO_SHA256);
            file_put_contents($sigPath, base64_encode($sig));
        }

        return [$zipPath, $sigPath];
    }

    protected function appFile(string $rel): ?string
    {
        $f = $this->site.'/'.$rel;

        return is_file($f) ? (string) file_get_contents($f) : null;
    }
}
