<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The signed-in user's in-app notifications (audit L5). Every query goes
 * through the user's own relation, so nobody can see or mark another's.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = $user->notifications()->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $n) => $this->present($n))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unreadCount' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['data' => $this->present($notification)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /** @return array<string, mixed> */
    private function present(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->data['kind'] ?? 'other',
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'target' => (object) ($n->data['target'] ?? []),
            'readAt' => $n->read_at?->toIso8601String(),
            'createdAt' => $n->created_at?->toIso8601String(),
        ];
    }
}
