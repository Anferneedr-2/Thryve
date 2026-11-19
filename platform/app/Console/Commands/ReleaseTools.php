<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZipArchive;

/**
 * Owner-side release tooling, run on the developer's machine (never on the server):
 *   php artisan thryve:release keygen <folder>            create a signing key pair
 *   php artisan thryve:release package <zip> --release=1.2.3 [--with-vendor]
 *   php artisan thryve:release sign <zip> <private-key.pem>   writes <zip>.sig
 * The server only ever holds the PUBLIC key (storage/app/update-public.pem).
 */
class ReleaseTools extends Command
{
    protected $signature = 'thryve:release {action : keygen | package | sign} {a? : folder, zip file} {b? : private key (sign)} {--release= : version for package} {--notes= : release notes} {--with-vendor : include vendor/}';

    protected $description = 'Create signing keys, build a release package, and sign it';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'keygen' => $this->keygen(),
            'package' => $this->package(),
            'sign' => $this->sign(),
            default => $this->bad('Unknown action. Use keygen, package or sign.'),
        };
    }

    private function bad(string $m): int
    {
        $this->error($m);

        return self::FAILURE;
    }

    private function keygen(): int
    {
        $dir = $this->argument('a');
        if (! is_string($dir) || ! is_dir($dir)) {
            return $this->bad('Give an existing folder to write the keys to (keep it OUTSIDE the project).');
        }
        try {
            [$private, $public] = \App\Updates\OpenSsl::newKeyPair(3072);
        } catch (\Throwable $e) {
            return $this->bad($e->getMessage());
        }
        file_put_contents(rtrim($dir, '/\\').'/thryve-release-private.pem', $private);
        file_put_contents(rtrim($dir, '/\\').'/thryve-release-public.pem', $public);
        $this->info('Created thryve-release-private.pem (keep secret, never upload) and thryve-release-public.pem.');
        $this->line('Copy the PUBLIC key to the server as storage/app/update-public.pem.');

        return self::SUCCESS;
    }

    private function package(): int
    {
        $out = $this->argument('a');
        $version = $this->option('release');
        if (! $out || ! preg_match('/^\d+\.\d+\.\d+$/', (string) $version)) {
            return $this->bad('Usage: thryve:release package <file.zip> --release=1.2.3');
        }
        $zip = new ZipArchive;
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return $this->bad('Could not create the zip file.');
        }
        $allowed = array_diff(config('thryve.update.allowed_paths'), ['VERSION'], $this->option('with-vendor') ? [] : ['vendor']);
        $skip = array_merge(config('thryve.update.protected_paths'), ['public/build/.vite']);
        $count = 0;
        foreach ($allowed as $path) {
            $full = base_path($path);
            if (is_file($full)) {
                $zip->addFile($full, $path);
                $count++;
            } elseif (is_dir($full)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));
                    if (! $f->isFile() || $f->isLink() || collect($skip)->contains(fn ($s) => $rel === $s || str_starts_with($rel, $s.'/'))) {
                        continue;
                    }
                    $zip->addFile($f->getPathname(), $rel);
                    $count++;
                }
            }
        }
        $zip->addFromString('release.json', json_encode(['version' => $version, 'notes' => (string) $this->option('notes'), 'requires_php' => config('thryve.min_php')], JSON_PRETTY_PRINT));
        $zip->close();
        $this->info("Packaged {$count} files into {$out}. Sign it next: thryve:release sign {$out} <private-key.pem>");

        return self::SUCCESS;
    }

    private function sign(): int
    {
        [$zip, $keyFile] = [$this->argument('a'), $this->argument('b')];
        if (! $zip || ! is_file($zip) || ! $keyFile || ! is_file($keyFile)) {
            return $this->bad('Usage: thryve:release sign <file.zip> <private-key.pem>');
        }
        $key = openssl_pkey_get_private((string) file_get_contents($keyFile));
        if (! $key || ! openssl_sign('thryve-release:'.hash_file('sha256', $zip), $signature, $key, OPENSSL_ALGO_SHA256)) {
            return $this->bad('Could not sign. Is that a valid private key?');
        }
        file_put_contents($zip.'.sig', base64_encode($signature));
        $this->info("Wrote {$zip}.sig. Upload the zip and the .sig file in System > Updates.");

        return self::SUCCESS;
    }
}
