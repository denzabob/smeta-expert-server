<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreManualPricingSourceRequest;
use App\Services\ManualPricingSourceService;
use Illuminate\Http\JsonResponse;

class ManualPricingSourceController extends Controller
{
    public function __construct(
        private readonly ManualPricingSourceService $manualPricingSourceService,
    ) {}

    public function store(StoreManualPricingSourceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $record = $this->manualPricingSourceService->create($request, $validated);

        return response()->json([
            'id' => $record->id,
            'target_type' => $validated['target_type'],
            'target_id' => (int) $validated['target_id'],
            'value' => (float) $validated['value'],
            'has_evidence' => true,
        ], 201);
    }
}