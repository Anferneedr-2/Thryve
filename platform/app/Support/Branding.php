<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Company logo and favicon, kept on the private disk and served through public routes (no storage symlink needed). */
final class Branding
{
    public const KINDS = ['logo', 'favicon'];

    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];

    private static function disk()
    {
        return Storage::disk(config('thryve.private_disk'));
    }

    /** @return string|null relative path of the stored file, if any */
    public static function find(string $kind): ?string
    {
        foreach (array_keys(self::TYPES) as $ext) {
            $path = "branding/{$kind}.{$ext}";
            if (self::disk()->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function has(string $kind): bool
    {
        return self::find($kind) !== null;
    }

    public static function store(string $kind, UploadedFile $file): void
    {
        // Extension comes from the detected content, never from the client's file name.
        $ext = match ($file->getMimeType()) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => throw new \InvalidArgumentException('Unsupported image type.'),
        };
        self::remove($kind);
        self::disk()->putFileAs('branding', $file, "{$kind}.{$ext}");
    }

    public static function remove(string $kind): void
    {
        while ($path = self::find($kind)) {
            self::disk()->delete($path);
        }
    }

    public static function contentType(string $path): string
    {
        return self::TYPES[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    public static function read(string $path): string
    {
        return self::disk()->get($path);
    }

    /** What the page needs to render the company's name and logo, with no secrets. */
    public static function summary(): array
    {
        return [
            'company_name' => Settings::get('company_name'),
            'default_theme' => Settings::get('default_theme'),
            'has_logo' => self::has('logo'),
            'has_favicon' => self::has('favicon'),
        ];
    }
}
