<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\ScreenshotCaptureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParserScreenshotController extends Controller
{
    public function __construct(
        private ScreenshotCaptureService $captureService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url|max:2048',
            'price' => 'required|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
            'region_id' => 'nullable|integer',
            'material_id' => 'nullable|integer',
            'revision_run_item_id' => 'nullable|integer',
        ]);

        $result = $this->captureService->captureByUrl(
            url: $validated['url'],
            price: (float) $validated['price'],
            currency: strtoupper((string) ($validated['currency'] ?? 'RUB')),
            regionId: $validated['region_id'] ?? null,
            materialId: $validated['material_id'] ?? null,
            revisionRunItemId: $validated['revision_run_item_id'] ?? null,
        );

        return response()->json($result);
    }
}
