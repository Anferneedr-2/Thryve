<?php

namespace App\Updates;

/** The installed release number, read from the VERSION file at the application root. */
final class Version
{
    public static function current(?string $base = null): string
    {
        $file = rtrim($base ?? config('thryve.update.base_path'), '/\\').DIRECTORY_SEPARATOR.'VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : '0.0.0';
    }

    public static function valid(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1;
    }

    /** True when $candidate is newer than $installed. */
    public static function isNewer(string $candidate, string $installed): bool
    {
        return version_compare($candidate, $installed, '>');
    }
}
