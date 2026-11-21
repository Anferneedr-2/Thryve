<?php

namespace App\Http\Controllers;

use App\Ai\FileReader;
use App\Ai\GeneratedFiles;
use App\Http\Responses\Api;
use App\Models\Attachment;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files people attach to chat. Stored on the private disk under random names, readable only through these routes by
 * their owner. A file that was sent with a message is kept with the conversation and is never deleted here.
 */
class AttachmentController extends Controller
{
    public function limits(): JsonResponse
    {
        return Api::ok([
            'max_count' => (int) Settings::get('file_max_count'),
            'max_mb' => (int) Settings::get('file_max_mb'),
            'types' => FileReader::KINDS,
            'image_max_mb' => 4,
            'pdf_max_mb' => 8,
            'output_formats' => GeneratedFiles::FORMATS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $maxMb = (int) Settings::get('file_max_mb');
        $maxCount = (int) Settings::get('file_max_count');
        $request->validate(['file' => ['required', 'file']]);
        $file = $request->file('file');

        if (! $file->isValid()) {
            return Api::error('upload_failed', 'The upload did not finish. Please try again.', 422);
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            return Api::error('file_too_large', "This file is larger than {$maxMb} MB.", 422);
        }
        if (Attachment::where('user_id', $request->user()->id)->whereNull('message_id')->count() >= $maxCount) {
            return Api::error('too_many_files', "You can attach up to {$maxCount} files to a message. Remove one first.", 422);
        }
        $kind = FileReader::kindFor($file->getClientOriginalName());
        if (! $kind) {
            return Api::error('file_type_not_allowed', 'Attach TXT, Markdown, CSV, DOCX, XLSX, text-based PDF, PNG or JPEG files.', 422);
        }

        try {
            $read = FileReader::read($file->getRealPath(), $kind);
        } catch (\InvalidArgumentException $e) {
            return Api::error('file_unreadable', $e->getMessage(), 422);
        }

        $key = Str::lower(Str::random(40)).'.bin'; // never derived from the uploaded name
        Storage::disk('private')->putFileAs('attachments', $file, $key);

        $a = Attachment::create([
            'user_id' => $request->user()->id,
            'original_name' => Str::limit(preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', $file->getClientOriginalName()), 250, ''),
            'kind' => $kind,
            'size' => $file->getSize(),
            'storage_key' => "attachments/{$key}",
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'extracted_text' => $read['text'],
            'truncated' => $read['truncated'],
        ]);

        return Api::ok(self::present($a), status: 201);
    }

    /** Remove a file that has not been sent yet (the person changed their mind). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $a = Attachment::where('user_id', $request->user()->id)->whereNull('message_id')->findOrFail($id);
        Storage::disk('private')->delete($a->storage_key);
        $a->delete();

        return Api::ok(null);
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $a = Attachment::where('user_id', $request->user()->id)->findOrFail($id);
        $name = str_replace('"', '', $a->original_name);

        return Storage::disk('private')->download($a->storage_key, $name, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public static function present(Attachment $a): array
    {
        $mime = match ($a->kind) {
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'csv' => 'text/csv', 'md' => 'text/markdown', default => 'text/plain',
        };

        return ['id' => $a->id, 'name' => $a->original_name, 'kind' => $a->kind, 'mime_type' => $mime, 'size' => (int) $a->size, 'truncated' => $a->truncated, 'generated' => $a->generated];
    }
}
