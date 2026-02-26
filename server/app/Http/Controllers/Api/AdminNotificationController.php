<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchNotificationJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNotificationController extends Controller
{
    /**
     * Admin-only guard.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || (int) $user->id !== 1) {
            abort(403, 'Access denied. Admin only.');
        }
    }

    /**
     * GET /api/admin/notifications
     * List notifications with filters + pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = Notification::query()
            ->orderByDesc('created_at');

        // Filters
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($audienceType = $request->input('audience_type')) {
            $query->where('audience_type', $audienceType);
        }
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        $notifications = $query->paginate($request->integer('per_page', 20));

        // Append stats
        $notificationIds = collect($notifications->items())->pluck('id');
        $stats = $this->getStatsForNotifications($notificationIds);

        $items = collect($notifications->items())->map(function ($n) use ($stats) {
            $s = $stats[$n->id] ?? ['delivered' => 0, 'read' => 0, 'clicked' => 0];
            return array_merge($n->toArray(), [
                'stats' => $s,
            ]);
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * POST /api/admin/notifications
     * Create a notification (draft or schedule).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|max:10000',
            'link_url' => 'nullable|string|max:2048',
            'link_label' => 'nullable|string|max:255',
            'link_type' => 'in:internal,external',
            'audience_type' => 'required|in:all,users,segment',
            'audience_payload' => 'nullable|array',
            'audience_payload.user_ids' => 'array',
            'audience_payload.user_ids.*' => 'integer|exists:users,id',
            'send_at' => 'nullable|date|after:now',
        ]);

        $status = $validated['send_at'] ? 'scheduled' : 'draft';

        $notification = Notification::create([
            'title' => $validated['title'] ?? null,
            'body' => $validated['body'],
            'link_url' => $validated['link_url'] ?? null,
            'link_label' => $validated['link_label'] ?? null,
            'link_type' => $validated['link_type'] ?? 'internal',
            'audience_type' => $validated['audience_type'],
            'audience_payload' => $validated['audience_payload'] ?? null,
            'status' => $status,
            'send_at' => $validated['send_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($notification, 201);
    }

    /**
     * GET /api/admin/notifications/{id}
     * Show a single notification with stats.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $notification = Notification::findOrFail($id);

        $stats = $this->getStatsForNotifications(collect([$id]));

        return response()->json(array_merge($notification->toArray(), [
            'stats' => $stats[$id] ?? ['delivered' => 0, 'read' => 0, 'clicked' => 0, 'target' => 0],
        ]));
    }

    /**
     * PUT /api/admin/notifications/{id}
     * Edit with status rules.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $notification = Notification::findOrFail($id);

        if (!$notification->isEditable()) {
            return response()->json([
                'message' => 'Нельзя редактировать отправленное или отменённое уведомление',
            ], 422);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'body' => 'sometimes|required|string|max:10000',
            'link_url' => 'nullable|string|max:2048',
            'link_label' => 'nullable|string|max:255',
            'link_type' => 'in:internal,external',
            'audience_type' => 'sometimes|in:all,users,segment',
            'audience_payload' => 'nullable|array',
            'audience_payload.user_ids' => 'array',
            'audience_payload.user_ids.*' => 'integer|exists:users,id',
            'send_at' => 'nullable|date|after:now',
        ]);

        $notification->update($validated);

        // If send_at set → scheduled; if cleared → draft
        if (array_key_exists('send_at', $validated)) {
            $notification->update([
                'status' => $validated['send_at'] ? 'scheduled' : 'draft',
            ]);
        }

        return response()->json($notification->fresh());
    }

    /**
     * POST /api/admin/notifications/{id}/send
     * Force send now.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $notification = Notification::findOrFail($id);

        if (!in_array($notification->status, ['draft', 'scheduled'])) {
            return response()->json([
                'message' => 'Уведомление нельзя отправить в текущем статусе',
            ], 422);
        }

        DispatchNotificationJob::dispatch($notification);

        return response()->json(['message' => 'Отправка запущена', 'status' => 'sending']);
    }

    /**
     * POST /api/admin/notifications/{id}/cancel
     * Cancel a notification.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $notification = Notification::findOrFail($id);

        if (!$notification->isCancellable()) {
            return response()->json([
                'message' => 'Уведомление нельзя отменить',
            ], 422);
        }

        $notification->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        return response()->json(['message' => 'Уведомление отменено']);
    }

    /**
     * GET /api/admin/notifications/{id}/stats
     * Detailed stats for a notification.
     */
    public function stats(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $notification = Notification::findOrFail($id);

        $target = $notification->resolveAudienceUserIds()->count();

        $delivered = UserNotification::where('notification_id', $id)
            ->whereNotNull('delivered_at')
            ->count();

        $read = UserNotification::where('notification_id', $id)
            ->whereNotNull('read_at')
            ->count();

        $clicked = UserNotification::where('notification_id', $id)
            ->whereNotNull('clicked_at')
            ->count();

        return response()->json([
            'target' => $target,
            'delivered' => $delivered,
            'read' => $read,
            'clicked' => $clicked,
            'read_rate' => $delivered > 0 ? round($read / $delivered * 100, 1) : 0,
            'ctr' => $delivered > 0 ? round($clicked / $delivered * 100, 1) : 0,
        ]);
    }

    /**
     * GET /api/admin/users/search
     * Search users for audience selection.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $search = $request->input('q', '');

        $users = User::query()
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            })
            ->select('id', 'name', 'email')
            ->limit(20)
            ->get();

        return response()->json($users);
    }

    /**
     * Bulk stats for a collection of notification IDs.
     */
    private function getStatsForNotifications(\Illuminate\Support\Collection $ids): array
    {
        if ($ids->isEmpty()) return [];

        $rows = DB::table('user_notifications')
            ->whereIn('notification_id', $ids)
            ->selectRaw('notification_id')
            ->selectRaw('COUNT(*) as delivered')
            ->selectRaw('SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) as `read`')
            ->selectRaw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')
            ->groupBy('notification_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->notification_id] = [
                'delivered' => (int) $row->delivered,
                'read' => (int) $row->read,
                'clicked' => (int) $row->clicked,
            ];
        }
        return $result;
    }
}
