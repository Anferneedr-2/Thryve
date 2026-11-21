<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * API envelope: success => {"data": ..., "meta": {...}?}; failure => {"error": {"code", "message", "details"?}}.
 */
final class Api
{
    public static function ok(mixed $data = null, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
