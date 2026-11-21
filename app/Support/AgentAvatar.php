<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * An optional picture for a chatbot. Without one the app shows the agent's letter on a coloured circle, so a picture is
 * only ever an addition. Files live on the private disk and are served through a signed-in route; only raster images
 * are accepted (never SVG, which can carry script).
 */
final class AgentAvatar
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    private static function disk()
    {
        return Storage::disk(config('thryve.private_disk'));
    }

    public static function find(int $agentId): ?string
    {
        foreach (array_keys(self::TYPES) as $ext) {
            $path = "agents/{$agentId}.{$ext}";
            if (self::disk()->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The address pages use for the picture (with a version so a new picture shows at once), or null when there is none. */
    public static function url(int $agentId): ?string
    {
        $path = self::find($agentId);

        return $path ? "/api/agents/{$agentId}/avatar?v=".self::disk()->lastModified($path) : null;
    }

    public static function store(int $agentId, UploadedFile $file): void
    {
        // The extension comes from the detected content, never from the client's file name.
        $ext = match ($file->getMimeType()) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
            default => throw new \InvalidArgumentException('Unsupported image type.'),
        };
        self::remove($agentId);
        self::disk()->putFileAs('agents', $file, "{$agentId}.{$ext}");
    }

    public static function remove(int $agentId): void
    {
        while ($path = self::find($agentId)) {
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
}
