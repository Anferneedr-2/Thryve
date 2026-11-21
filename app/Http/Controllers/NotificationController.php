<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A person's own notifications. Every query is scoped to the signed-in user; other people's are simply not found. */
class NotificationController extends Controller
{
    private function present(Notification $n): array
    {
        return ['id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'data' => $n->data, 'read' => $n->read_at !== null, 'created_at' => $n->created_at->toIso8601String()];
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,50']]);
        $q = Notification::where('user_id', $request->user()->id)->orderByDesc('id');
        if (! empty($f['unread'])) {
            $q->whereNull('read_at');
        }
        $page = $q->paginate($f['per_page'] ?? 20);

        return Api::ok(
            $page->getCollection()->map(fn ($n) => $this->present($n))->values(),
            ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'unread' => self::unreadFor($request->user()->id)],
        );
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $n = Notification::where('user_id', $request->user()->id)->findOrFail($id);
        if (! $n->read_at) {
            $n->update(['read_at' => now()]);
        }

        return Api::ok(['unread' => self::unreadFor($request->user()->id)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return Api::ok(['unread' => 0]);
    }

    public static function unreadFor(int $userId): int
    {
        return Notification::where('user_id', $userId)->whereNull('read_at')->count();
    }
}
