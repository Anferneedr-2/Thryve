<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Company logo (light and dark versions) and favicon, kept on the private disk and served through public routes (no storage symlink needed). */
final class Branding
{
    /**
     * `logo_dark` is the logo used on the dark theme (the normal logo may be dark and vanish on a dark background).
     * `logo_login` is the logo on the blue panel of the sign-in page (normally a white or light version on a transparent background).
     */
    public const KINDS = ['logo', 'logo_dark', 'logo_login', 'favicon'];

    /** Square PNG sizes made from the favicon: tab (32), iPhone home screen (180), Android and web app (192, 512). */
    public const ICON_SIZES = [32, 180, 192, 512];

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

    /** Only a PNG favicon can be resized into the app icons (an ICO is used as it is, for the tab). */
    public static function faviconIsPng(): bool
    {
        return str_ends_with((string) self::find('favicon'), '.png');
    }

    /** Changes whenever the favicon does, so icon addresses with ?v= pick up a new file at once. */
    public static function faviconVersion(): ?int
    {
        $path = self::find('favicon');

        return $path ? self::disk()->lastModified($path) : null;
    }

    /** The favicon as a square PNG of the given size (centred on a transparent square; white for the iPhone icon), or null when it cannot be made. */
    public static function icon(int $size): ?string
    {
        if (! in_array($size, self::ICON_SIZES, true) || ! self::faviconIsPng()) {
            return null;
        }
        $src = @imagecreatefromstring(self::read(self::find('favicon')));
        if (! $src) {
            return null;
        }
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min($size / $w, $size / $h);
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        // iPhones draw a transparent home-screen icon on black, so that one (180) sits on white; the others stay transparent.
        imagefill($dst, 0, 0, $size === 180 ? imagecolorallocate($dst, 255, 255, 255) : imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($dst);
        $png = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return $png;
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
            'has_logo_dark' => self::has('logo_dark'),
            'has_logo_login' => self::has('logo_login'),
            'has_favicon' => self::has('favicon'),
            'favicon_png' => self::faviconIsPng(),
            'favicon_v' => self::faviconVersion(),
        ];
    }
}
