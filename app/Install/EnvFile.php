<?php

namespace App\Install;

use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal .env writer: updates or appends KEY=value lines, leaves everything else untouched.
 * Plain values are written bare; anything else is double-quoted with \\, \" and \$ escaped.
 */
final class EnvFile
{
    public static function set(string $path, array $values, ?string $templatePath = null): void
    {
        $lines = [];
        $source = is_file($path) ? $path : ($templatePath && is_file($templatePath) ? $templatePath : null);
        if ($source) {
            // Drop a UTF-8 BOM (some editors add one); it would corrupt the first key.
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($source));
            $lines = preg_split('/\R/', rtrim($raw, "\r\n"));
        }

        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException('Invalid environment key.');
            }
            $line = $key.'='.self::format((string) $value);
            $found = false;
            foreach ($lines as $i => $existing) {
                if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=/', $existing)) {
                    $lines[$i] = $line;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $lines[] = $line;
            }
        }

        $content = implode("\n", $lines)."\n";
        // Nothing changed: leave the file alone. Rewriting it would still bump its timestamp, and `php artisan serve`
        // restarts whenever .env changes, which cuts off a retried install halfway through.
        if (is_file($path) && (string) file_get_contents($path) === $content) {
            return;
        }

        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('The environment file is not writable.');
        }
    }

    public static function format(string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('Environment values cannot contain line breaks.');
        }
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_.\-\/:@=+,]+$/', $value)) {
            return $value;
        }

        // Double quotes: dotenv honours only \\ \" and \$ escapes there; escaping $ prevents ${VAR} interpolation.
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
