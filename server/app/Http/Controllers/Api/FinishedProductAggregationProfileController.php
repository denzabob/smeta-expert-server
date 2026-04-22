<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedProductAggregationProfile;
use App\Services\RefreshFinishedProductComputedPriceService;
use App\Services\FinishedProductSpecificationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinishedProductAggregationProfileController extends Controller
{
    public function __construct(
        private FinishedProductSpecificationAccessService $accessService,
        private RefreshFinishedProductComputedPriceService $refreshService,
    ) {}

    public function update(Request $request, int $specification): JsonResponse
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $specification);

        $validated = $request->validate([
            'method' => 'required|in:mean,median',
            'include_only_active' => 'nullable|boolean',
            'exclude_stale' => 'nullable|boolean',
            'minimum_sources_count' => 'nullable|integer|min:1',
            'metadata' => 'nullable|array',
        ]);

        $profile = FinishedProductAggregationProfile::updateOrCreate(
            ['finished_product_specification_id' => $specification->id],
            [
                'method' => $validated['method'],
                'include_only_active' => $validated['include_only_active'] ?? true,
                'exclude_stale' => $validated['exclude_stale'] ?? true,
                'minimum_sources_count' => $validated['minimum_sources_count'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ]
        );

        $computed = $this->refreshService->refresh($specification);

        return response()->json([
            'profile' => $profile->fresh(),
            'computed_price' => $computed,
        ]);
    }
}
