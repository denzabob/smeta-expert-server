<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    /**
     * GET /api/notifications
     * User's notification feed (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = UserNotification::forUser($userId)
            ->visible()
            ->with(['notification:id,title,body,link_url,link_label,link_type,created_at'])
            ->orderByDesc('delivered_at');

        if ($request->input('filter') === 'unread') {
            $query->unread();
        } elseif ($request->input('filter') === 'read') {
            $query->read();
        }

        $items = $query->paginate($request->integer('per_page', 30));

        $mapped = collect($items->items())->map(fn ($un) => [
            'id' => $un->id,
            'notification_id' => $un->notification_id,
            'title' => $un->notification->title ?? null,
            'body' => $un->notification->body ?? '',
            'link_url' => $un->notification->link_url ?? null,
            'link_label' => $un->notification->link_label ?? null,
            'link_type' => $un->notification->link_type ?? 'internal',
            'delivered_at' => $un->delivered_at,
            'read_at' => $un->read_at,
            'clicked_at' => $un->clicked_at,
        ]);

        return response()->json([
            'data' => $mapped,
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::forUser($request->user()->id)
            ->visible()
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * POST /api/notifications/{id}/read
     */
    public function read(Request $request, int $id): JsonResponse
    {
        $un = UserNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $un->markAsRead();

        return response()->json(['message' => 'ok']);
    }

    /**
     * POST /api/notifications/read-all
     */
    public function readAll(Request $request): JsonResponse
    {
        UserNotification::forUser($request->user()->id)
            ->visible()
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    /**
     * POST /api/notifications/{id}/click
     */
    public function click(Request $request, int $id): JsonResponse
    {
        $un = UserNotification::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $un->markAsClicked();

        return response()->json(['message' => 'ok']);
    }
}
