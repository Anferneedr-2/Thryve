<?php

namespace App\Updates;

use RuntimeException;
use ZipArchive;

/** Zip handling that refuses path tricks (zip-slip), absolute paths, symlinks and oversized archives. */
final class SafeZip
{
    public const MAX_ENTRIES = 50000;

    public const MAX_UNCOMPRESSED_BYTES = 512 * 1024 * 1024;

    /** @return string a clean relative path using forward slashes */
    public static function cleanName(string $name): string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)) {
            throw new RuntimeException('The package contains an unsafe file path.');
        }
        foreach (explode('/', rtrim($name, '/')) as $part) {
            if ($part === '..' || $part === '.' || $part === '') {
                throw new RuntimeException('The package contains an unsafe file path.');
            }
        }

        return rtrim($name, '/');
    }

    /** Open an archive and check every entry before anything is extracted. @return list<array{name: string, dir: bool, size: int}> */
    public static function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('The package has too many files.');
        }
        $entries = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $isDir = str_ends_with($stat['name'], '/');
            $name = self::cleanName($stat['name']);
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('The package contains a symbolic link, which is not allowed.');
            }
            $total += $stat['size'];
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('The package is too large when unpacked.');
            }
            $entries[] = ['name' => $name, 'dir' => $isDir, 'size' => (int) $stat['size']];
        }

        return $entries;
    }

    /** Extract the named entries under $dest, creating folders as needed. */
    public static function extract(ZipArchive $zip, string $dest, ?array $only = null): void
    {
        $dest = rtrim($dest, '/\\');
        foreach (self::inspect($zip) as $i => $e) {
            if ($only !== null && ! in_array($e['name'], $only, true)) {
                continue;
            }
            $target = $dest.'/'.$e['name'];
            if ($e['dir']) {
                @mkdir($target, 0775, true);

                continue;
            }
            @mkdir(dirname($target), 0775, true);
            $in = $zip->getStream($zip->getNameIndex($i));
            if (! $in) {
                throw new RuntimeException('A file in the package could not be read.');
            }
            $out = fopen($target, 'wb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
    }
}
