<?php

namespace App\Http\Controllers;

use App\Ai\ChatService;
use App\Ai\NoProviderAvailable;
use App\Ai\ProviderException;
use App\Ai\TokenLimitReached;
use App\Http\Responses\Api;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Private conversations: every query is scoped to the signed-in person, and another person's id looks like "not found". */
class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    private function mine(Request $request, int|string $id): Conversation
    {
        return Conversation::visible()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'archived' => ['nullable', 'boolean']]);
        $q = Conversation::visible()->where('user_id', $request->user()->id);
        ($f['archived'] ?? false) ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        if (filled($f['q'] ?? null)) {
            $like = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)
                ->orWhereExists(fn ($m) => $m->selectRaw('1')->from('messages')->whereColumn('messages.conversation_id', 'conversations.id')->whereNull('superseded_at')->where('content', 'like', $like)));
        }

        return Api::ok($q->orderByDesc('last_message_at')->orderByDesc('id')->limit(100)->get()->map(fn ($c) => $this->summary($c)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $c = $this->mine($request, $id);

        return Api::ok($this->summary($c) + ['messages' => $c->messages()->whereNull('superseded_at')->orderBy('id')->get()->map(fn ($m) => $this->message($m))]);
    }

    /** Start a conversation (or continue one) with a new message. */
    public function send(Request $request): JsonResponse
    {
        $d = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'conversation_id' => ['nullable', 'integer'],
            'acknowledge_sensitive' => ['nullable', 'boolean'],
        ]);
        $text = trim($d['message']);
        if ($text === '') {
            return Api::error('validation_failed', 'Type a message first.', 422, ['message' => ['Type a message first.']]);
        }
        if ($blocked = $this->sensitiveResponse($text, (bool) ($d['acknowledge_sensitive'] ?? false))) {
            return $blocked;
        }

        $c = isset($d['conversation_id']) ? $this->mine($request, $d['conversation_id']) : $this->chat->start($request->user(), $text);
        $this->chat->addUserMessage($c, $text);

        return $this->respond($request, $c);
    }

    /** Ask again for the last user message: after a failure, or to replace the current answer. */
    public function regenerate(Request $request, int $id): JsonResponse
    {
        $c = $this->mine($request, $id);
        $this->chat->supersedeLastAnswer($c);
        if (! $c->messages()->whereNull('superseded_at')->exists()) {
            return Api::error('nothing_to_answer', 'This conversation has no message to answer.', 409);
        }

        return $this->respond($request, $c);
    }

    /** Edit a user message. It and everything after it are replaced (kept as hidden history) and a new answer is requested. */
    public function edit(Request $request, int $id, int $messageId): JsonResponse
    {
        $c = $this->mine($request, $id);
        $d = $request->validate(['message' => ['required', 'string', 'max:20000'], 'acknowledge_sensitive' => ['nullable', 'boolean']]);
        $original = $c->messages()->whereNull('superseded_at')->where('role', 'user')->findOrFail($messageId);
        $text = trim($d['message']);
        if ($text === '') {
            return Api::error('validation_failed', 'Type a message first.', 422, ['message' => ['Type a message first.']]);
        }
        if ($blocked = $this->sensitiveResponse($text, (bool) ($d['acknowledge_sensitive'] ?? false))) {
            return $blocked;
        }
        $this->chat->supersedeFrom($c, $original);
        $this->chat->addUserMessage($c, $text);

        return $this->respond($request, $c);
    }

    public function rate(Request $request, int $id, int $messageId): JsonResponse
    {
        $c = $this->mine($request, $id);
        $d = $request->validate(['rating' => ['nullable', 'in:up,down']]);
        $m = $c->messages()->whereNull('superseded_at')->where('role', 'assistant')->findOrFail($messageId);
        $m->update(['rating' => $d['rating'] ?? null]);

        return Api::ok($this->message($m));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = $this->mine($request, $id);
        $d = $request->validate(['title' => ['sometimes', 'string', 'min:1', 'max:150'], 'archived' => ['sometimes', 'boolean']]);
        if (isset($d['title'])) {
            $c->title = trim($d['title']);
        }
        if (array_key_exists('archived', $d)) {
            $c->archived_at = $d['archived'] ? now() : null;
        }
        $c->save();

        return Api::ok($this->summary($c));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->mine($request, $id)->update(['deleted_at' => now()]);

        return Api::ok(null);
    }

    private function sensitiveResponse(string $text, bool $acknowledged): ?JsonResponse
    {
        $found = $this->chat->sensitive($text);
        if (! $found) {
            return null;
        }
        $what = implode(', ', $found);
        if (Settings::get('sensitive_data_mode') === 'block') {
            return Api::error('sensitive_blocked', "This message looks like it contains {$what}. Remove it and try again.", 422, ['found' => $found]);
        }
        if (! $acknowledged) {
            return Api::error('sensitive_warning', "This message looks like it contains {$what}. Send it anyway only if it is safe to share.", 409, ['found' => $found]);
        }

        return null;
    }

    private function respond(Request $request, Conversation $c): JsonResponse
    {
        $base = ['conversation' => $this->summary($c)];
        try {
            $reply = $this->chat->answer($request->user(), $c);
        } catch (TokenLimitReached $e) {
            return Api::error('token_limit', $e->getMessage(), 429, $base);
        } catch (NoProviderAvailable) {
            return Api::error('ai_unavailable', 'The assistant is not available right now. Your message is saved: try again in a moment.', 503, $base);
        } catch (ProviderException $e) {
            $msg = match ($e->category) {
                'timeout' => 'The assistant is taking too long. It may still finish: check this conversation again in a minute before sending the message twice.',
                'bad_request' => 'The assistant could not handle this message. Try rephrasing it.',
                default => 'The assistant could not answer. Your message is saved: try again.',
            };

            return Api::error('ai_failed', $msg, 502, $base);
        }

        return Api::ok($base + ['reply' => $this->message($reply)]);
    }

    private function summary(Conversation $c): array
    {
        return ['id' => $c->id, 'title' => $c->title, 'archived' => $c->archived_at !== null, 'last_message_at' => $c->last_message_at?->toIso8601String()];
    }

    private function message(Message $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'rating' => $m->rating, 'created_at' => $m->created_at->toIso8601String()];
    }
}
