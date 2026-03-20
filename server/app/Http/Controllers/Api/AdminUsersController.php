<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminUsersController extends Controller
{
    public function __construct(
        private AdminUserService $userService
    ) {}

    /**
     * GET /api/admin/users
     * List users with search, filtering, sorting, pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = User::withTrashed()
            ->select([
                'users.*',
                DB::raw('(SELECT COUNT(*) FROM ai_logs WHERE ai_logs.user_id = users.id) as ai_requests_count'),
            ]);

        // Search
        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('users.id', '=', is_numeric($search) ? (int) $search : 0)
                  ->orWhere('users.name', 'like', "%{$search}%")
                  ->orWhere('users.email', 'like', "%{$search}%")
                  ->orWhere('users.phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        $status = $request->query('status');
        if ($status === 'active') {
            $query->whereNull('users.deleted_at')->where('users.auth_status', 'active');
        } elseif ($status === 'blocked') {
            $query->whereNull('users.deleted_at')->where('users.auth_status', 'blocked');
        } elseif ($status === 'deleted') {
            $query->whereNotNull('users.deleted_at');
        }

        // Filter by role
        $role = $request->query('role');
        if ($role) {
            $query->where('users.role', $role);
        }

        // Sorting
        $sortBy = $request->query('sort_by', 'created_at');
        $sortDir = $request->query('sort_dir', 'desc');
        $allowedSortFields = ['created_at', 'last_login_at', 'ai_requests_count', 'name', 'email', 'id'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }
        $sortDir = in_array($sortDir, ['asc', 'desc']) ? $sortDir : 'desc';

        if ($sortBy === 'ai_requests_count') {
            $query->orderBy('ai_requests_count', $sortDir);
        } else {
            $query->orderBy("users.{$sortBy}", $sortDir);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginated = $query->paginate($perPage);

        // Make auth_status visible (it's $hidden on User model)
        $paginated->getCollection()->each(fn ($u) => $u->makeVisible(['auth_status']));

        // Get summary metrics
        $metrics = $this->getMetrics();

        return response()->json([
            'users' => $paginated->items(),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
            'metrics' => $metrics,
        ]);
    }

    /**
     * GET /api/admin/users/{id}
     * Get detailed user info (card).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::withTrashed()->findOrFail($id);

        // AI stats
        $aiStats = DB::table('ai_logs')
            ->where('user_id', $id)
            ->select([
                DB::raw('COUNT(*) as total_requests'),
                DB::raw('SUM(CASE WHEN is_successful THEN 1 ELSE 0 END) as successful_requests'),
                DB::raw('SUM(COALESCE(cost_usd, 0)) as total_cost'),
                DB::raw('SUM(COALESCE(prompt_tokens, 0) + COALESCE(completion_tokens, 0)) as total_tokens'),
                DB::raw('MAX(created_at) as last_used_at'),
            ])
            ->first();

        // Related entities counts
        $dependencies = $this->userService->getDependencies($user);

        // Audit log for this user
        $auditLog = AdminAuditLog::where('target_user_id', $id)
            ->with('admin:id,name,email')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Settings
        $settings = DB::table('user_settings')->where('user_id', $id)->first();

        // Social accounts
        $socialAccounts = DB::table('social_accounts')->where('user_id', $id)->whereNull('unlinked_at')->get();

        // Active tokens count
        $tokensCount = $user->tokens()->count();

        $this->userService->audit(
            $request->user()->id,
            $id,
            'view',
            'success',
            null,
            null,
            $request->ip()
        );

        return response()->json([
            'user' => $user->makeVisible(['auth_status']),
            'ai_stats' => $aiStats,
            'dependencies' => $dependencies,
            'audit_log' => $auditLog,
            'settings' => $settings,
            'social_accounts' => $socialAccounts,
            'tokens_count' => $tokensCount,
        ]);
    }

    /**
     * POST /api/admin/users/{id}/block
     */
    public function block(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $targetUser = User::findOrFail($id);

        // Safety checks
        if ($targetUser->id === $admin->id) {
            return response()->json(['error' => 'Нельзя заблокировать свою учетную запись'], 403);
        }
        if ($targetUser->isSuperAdmin() && !$admin->isSuperAdmin()) {
            return response()->json(['error' => 'Недостаточно прав для блокировки суперадминистратора'], 403);
        }
        if ($targetUser->isBlocked()) {
            return response()->json(['error' => 'Пользователь уже заблокирован'], 422);
        }

        try {
            $this->userService->blockUser($targetUser, $admin, $request->input('reason'), $request->ip());
            return response()->json(['message' => 'Пользователь заблокирован', 'user' => $targetUser->fresh()->makeVisible(['auth_status'])]);
        } catch (\Throwable $e) {
            $this->userService->audit($admin->id, $id, 'block', 'error', null, ['error' => $e->getMessage()], $request->ip());
            return response()->json(['error' => 'Ошибка при блокировке: ' . $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/admin/users/{id}/unblock
     */
    public function unblock(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $admin = $request->user();
        $targetUser = User::findOrFail($id);

        if (!$targetUser->isBlocked()) {
            return response()->json(['error' => 'Пользователь не заблокирован'], 422);
        }

        try {
            $this->userService->unblockUser($targetUser, $admin, $request->input('reason'), $request->ip());
            return response()->json(['message' => 'Пользователь разблокирован', 'user' => $targetUser->fresh()->makeVisible(['auth_status'])]);
        } catch (\Throwable $e) {
            $this->userService->audit($admin->id, $id, 'unblock', 'error', null, ['error' => $e->getMessage()], $request->ip());
            return response()->json(['error' => 'Ошибка при разблокировке: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/admin/users/{id}
     * Soft delete.
     */
    public function softDelete(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $admin = $request->user();
        $targetUser = User::findOrFail($id);

        if ($targetUser->id === $admin->id) {
            return response()->json(['error' => 'Нельзя удалить свою учетную запись'], 403);
        }
        if ($targetUser->isSuperAdmin() && !$admin->isSuperAdmin()) {
            return response()->json(['error' => 'Недостаточно прав для удаления суперадминистратора'], 403);
        }

        try {
            $this->userService->softDeleteUser($targetUser, $admin, $request->input('reason'), $request->ip());
            return response()->json(['message' => 'Пользователь деактивирован (soft delete)']);
        } catch (\Throwable $e) {
            $this->userService->audit($admin->id, $id, 'soft_delete', 'error', null, ['error' => $e->getMessage()], $request->ip());
            return response()->json(['error' => 'Ошибка при удалении: ' . $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/admin/users/{id}/force
     * Hard delete — superadmin only.
     */
    public function hardDelete(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $admin = $request->user();

        if (!$admin->isSuperAdmin()) {
            return response()->json(['error' => 'Hard delete доступен только суперадминистраторам'], 403);
        }

        $targetUser = User::withTrashed()->findOrFail($id);

        if ($targetUser->id === $admin->id) {
            return response()->json(['error' => 'Нельзя удалить свою учетную запись'], 403);
        }
        if ($targetUser->isSuperAdmin()) {
            return response()->json(['error' => 'Нельзя удалить суперадминистратора'], 403);
        }

        try {
            $this->userService->hardDeleteUser($targetUser, $admin, $request->input('reason'), $request->ip());
            return response()->json(['message' => 'Пользователь полностью удален (hard delete)']);
        } catch (\Throwable $e) {
            $this->userService->audit($admin->id, $id, 'hard_delete', 'error', null, ['error' => $e->getMessage()], $request->ip());
            return response()->json(['error' => 'Ошибка при удалении: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/users/{id}/dependencies
     * Preview dependencies before deletion.
     */
    public function dependencies(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::withTrashed()->findOrFail($id);
        $deps = $this->userService->getDependencies($user);

        // Classify strategies
        $strategies = [
            'cascade_delete' => ['projects', 'operations', 'suppliers', 'import_sessions', 'ideas',
                'idea_votes', 'idea_comments', 'trusted_devices', 'social_accounts', 'tokens',
                'notifications', 'user_settings', 'user_material_library', 'operation_groups',
                'chrome_ext_logs', 'detail_types', 'revision_runs',
                'collect_profiles', 'price_import_sessions'],
            'nullify' => ['project_revisions'],
            'preserve_anonymized' => ['ai_logs'],
        ];

        return response()->json([
            'dependencies' => $deps,
            'strategies' => $strategies,
            'total_records' => array_sum($deps),
            'has_blocking_dependencies' => false, // Currently no blocking dependencies
        ]);
    }

    /**
     * POST /api/admin/users/{id}/restore
     * Restore a soft-deleted user.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $user = User::withTrashed()->findOrFail($id);

        if (!$user->trashed()) {
            return response()->json(['error' => 'Пользователь не удален'], 422);
        }

        $user->restore();
        $user->update(['auth_status' => 'active']);

        $this->userService->audit(
            $request->user()->id,
            $id,
            'restore',
            'success',
            $request->input('reason'),
            null,
            $request->ip()
        );

        return response()->json(['message' => 'Пользователь восстановлен', 'user' => $user->fresh()->makeVisible(['auth_status'])]);
    }

    /**
     * PUT /api/admin/users/{id}/role
     * Change user role.
     */
    public function updateRole(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validator = Validator::make($request->all(), [
            'role' => 'required|string|in:user,admin',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $targetUser = User::findOrFail($id);
        $newRole = $request->input('role');

        // Superadmin is hardcoded to id=1, cannot be assigned
        if ($newRole === 'superadmin') {
            return response()->json(['error' => 'Роль суперадминистратора назначается только через БД'], 403);
        }

        // Cannot change own role
        if ($targetUser->id === $admin->id) {
            return response()->json(['error' => 'Нельзя изменить свою роль'], 403);
        }

        $oldRole = $targetUser->role;
        $targetUser->update(['role' => $newRole]);

        $this->userService->audit(
            $admin->id,
            $id,
            'role_change',
            'success',
            null,
            ['old_role' => $oldRole, 'new_role' => $newRole],
            $request->ip()
        );

        return response()->json(['message' => 'Роль изменена', 'user' => $targetUser->fresh()->makeVisible(['auth_status'])]);
    }

    /**
     * GET /api/admin/users/audit-log
     * Get audit log for all admin actions.
     */
    public function auditLog(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = AdminAuditLog::with(['admin:id,name,email', 'targetUser:id,name,email'])
            ->orderByDesc('created_at');

        $action = $request->query('action');
        if ($action) {
            $query->where('action', $action);
        }

        $targetUserId = $request->query('target_user_id');
        if ($targetUserId) {
            $query->where('target_user_id', $targetUserId);
        }

        $perPage = min((int) $request->query('per_page', 50), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'logs' => $paginated->items(),
            'pagination' => [
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * POST /api/admin/users/bulk-action
     * Bulk operations: block, unblock, soft_delete.
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:block,unblock,soft_delete',
            'user_ids' => 'required|array|min:1|max:100',
            'user_ids.*' => 'required|integer',
            'reason' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $action = $request->input('action');
        $userIds = $request->input('user_ids');
        $reason = $request->input('reason', 'Массовая операция');

        $results = match ($action) {
            'block' => $this->userService->bulkBlock($userIds, $admin, $reason, $request->ip()),
            'unblock' => $this->userService->bulkUnblock($userIds, $admin, $reason, $request->ip()),
            'soft_delete' => $this->userService->bulkSoftDelete($userIds, $admin, $reason, $request->ip()),
        };

        return response()->json([
            'message' => "Выполнено: {$results['success']} успешно, {$results['failed']} с ошибкой",
            'results' => $results,
        ]);
    }

    /**
     * Summary metrics for the users admin section.
     */
    private function getMetrics(): array
    {
        return [
            'total' => User::withTrashed()->count(),
            'active' => User::where('auth_status', 'active')->count(),
            'blocked' => User::where('auth_status', 'blocked')->count(),
            'deleted' => User::onlyTrashed()->count(),
            'total_ai_requests' => DB::table('ai_logs')->count(),
        ];
    }

    /**
     * Authorize admin access.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
