<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Billing\BillingAdminQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminBillingController extends Controller
{
    public function __construct(
        private BillingAdminQueryService $queryService,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->queryService->overview($request));
    }

    public function userOverview(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->queryService->userOverview($user, $request));
    }

    public function usage(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'metric_code' => 'nullable|string|max:100',
            'feature_code' => 'nullable|string|max:100',
            'project_id' => 'nullable|integer|min:1',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        return response()->json($this->queryService->usage($request->merge($validated)));
    }

    public function events(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'metric_code' => 'nullable|string|max:100',
            'feature_code' => 'nullable|string|max:100',
            'project_id' => 'nullable|integer|min:1',
            'source' => 'nullable|string|max:40',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        return response()->json($this->queryService->events($request->merge($validated)));
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
