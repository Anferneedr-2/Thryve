<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Support\AgentKeyTransfer;
use App\Support\Audit;
use App\Support\ChatTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Super Admin: brings a chatbot's chats and its access key from another installation, for hosts with no terminal.
 * The same safe rules as the command-line tools: people are matched by username and never created, repeats are skipped,
 * and the key only opens with the passphrase typed here (never stored or logged).
 */
class TransferController extends Controller
{
    /** How big a file this server accepts, so the page can say so before a big upload fails. */
    public function show(): JsonResponse
    {
        $bytes = fn (string $v) => (int) (preg_match('/^(\d+)([KMG]?)/i', trim($v), $m) ? $m[1] * (['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])]) : 0);

        return Api::ok(['max_upload_bytes' => min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX)]);
    }

    public function chats(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:204800'], 'dry_run' => ['sometimes', 'boolean']]);
        $bundle = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($bundle)) {
            return Api::error('invalid_file', 'That file is not a chat transfer file made by Thryve.', 422);
        }
        $dry = $request->boolean('dry_run');
        try {
            $report = ChatTransfer::import($bundle, $dry);
        } catch (InvalidArgumentException $e) {
            return Api::error('invalid_file', $e->getMessage(), 422);
        }
        if (! $dry && $report['imported'] > 0) {
            Audit::log('chats.imported', $request->user(), null, ['agent' => $bundle['agent']['slug'] ?? null, 'conversations' => $report['imported'], 'messages' => $report['messages'], 'files' => $report['files']]);
        }

        return Api::ok($report);
    }

    public function key(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:64'], 'passphrase' => ['required', 'string', 'max:500'], 'replace' => ['sometimes', 'boolean']]);
        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        if (! is_array($data)) {
            return Api::error('invalid_file', 'That file is not a chatbot key file made by Thryve.', 422);
        }
        try {
            $done = AgentKeyTransfer::install($data, (string) $request->input('passphrase'), $request->boolean('replace'), $request->user());
        } catch (InvalidArgumentException $e) {
            return Api::error('invalid_file', $e->getMessage(), 422);
        }

        return Api::ok($done);
    }
}
