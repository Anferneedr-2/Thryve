<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reserve the user's chat mutation before editing or appending any conversation messages.
 *
 * The reservation is a short lease, not a fixed long wait: a streaming answer renews it every few seconds for as long as it runs,
 * so an answer may take as long as Hermes needs, while a request whose server process died frees the person within the lease.
 */
class PrepareChatRequest
{
    /** How long a reservation lasts without being renewed. */
    public const LEASE_SECONDS = 120;

    public function handle(Request $request, Closure $next): mixed
    {
        $stream = str_contains($request->header('Accept', ''), 'text/event-stream');
        $id = $stream ? $request->header('X-Chat-Stream-Id') : (string) Str::uuid();
        abort_unless(is_string($id) && Str::isUuid($id), 422, 'A valid chat stream ID is required.');
        // A person may chat in several conversations at once; only one request per conversation may run at a time.
        $conversationId = $request->route('id') ?? $request->input('conversation_id');
        $conversationId = is_numeric($conversationId) ? (int) $conversationId : null;
        DB::transaction(function () use ($request, $id, $conversationId) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            abort_if($conversationId !== null && DB::table('chat_requests')->where('user_id', $request->user()->id)->where('conversation_id', $conversationId)->where('status', 'running')->where('expires_at', '>', now())->exists(), 409, 'This chat is already answering. Stop it or wait for it to finish.');
            abort_if(DB::table('chat_requests')->where('id', $id)->exists(), 409, 'This chat request was already submitted.');
            DB::table('chat_requests')->insert(['id' => $id, 'user_id' => $request->user()->id, 'conversation_id' => $conversationId, 'status' => 'running', 'cancel_requested' => false, 'expires_at' => now()->addSeconds(max(self::LEASE_SECONDS, (int) config('services.hermes.timeout') + 60)), 'created_at' => now(), 'updated_at' => now()]);
        });
        $request->attributes->set('chat_request_id', $id);
        $response = null;
        try {
            $response = $next($request);

            return $response;
        } finally {
            if (! $response instanceof StreamedResponse) {
                DB::table('chat_requests')->where('id', $id)->update(['status' => $response && $response->isSuccessful() ? 'completed' : 'failed', 'updated_at' => now()]);
            }
        }
    }
}
