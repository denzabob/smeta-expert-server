<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedProductAggregationProfile;
use App\Services\FinishedProductPricingBreakdownReadService;
use App\Services\RefreshFinishedProductComputedPriceService;
use App\Services\FinishedProductSpecificationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinishedProductComputedPriceController extends Controller
{
    public function __construct(
        private FinishedProductSpecificationAccessService $accessService,
        private RefreshFinishedProductComputedPriceService $refreshService,
        private FinishedProductPricingBreakdownReadService $breakdownReadService,
    ) {}

    public function show(Request $request, int $specification): JsonResponse
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $specification);
        $computed = $this->refreshService->refresh($specification);
        $profile = FinishedProductAggregationProfile::firstOrCreate(
            ['finished_product_specification_id' => $specification->id],
            [
                'method' => FinishedProductAggregationProfile::METHOD_MEDIAN,
                'include_only_active' => true,
                'exclude_stale' => true,
            ]
        );

        return response()->json([
            'finished_product_specification_id' => $specification->id,
            'profile' => $profile,
            'computed_price' => $computed,
        ]);
    }

    public function breakdown(Request $request, int $specification): JsonResponse
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $specification);

        return response()->json(
            $this->breakdownReadService->forSpecification($specification)
        );
    }
}
