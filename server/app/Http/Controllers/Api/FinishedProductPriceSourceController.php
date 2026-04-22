<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;
use App\Services\FinishedProductPriceSourceDetailsReadService;
use App\Services\RefreshFinishedProductComputedPriceService;
use App\Services\FinishedProductSpecificationAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinishedProductPriceSourceController extends Controller
{
    public function __construct(
        private FinishedProductSpecificationAccessService $accessService,
        private FinishedProductPriceSourceDetailsReadService $detailsReadService,
        private RefreshFinishedProductComputedPriceService $refreshService,
    ) {}

    public function index(Request $request, FinishedProductSpecification $specification): JsonResponse
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $specification);

        $sources = FinishedProductPriceSource::query()
            ->forSpecification($specification->id)
            ->with(['supplier', 'priceListVersion'])
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'finished_product_specification_id' => $specification->id,
            'sources' => $sources,
        ]);
    }

    public function details(Request $request, FinishedProductPriceSource $source): JsonResponse
    {
        $source = $this->accessService->resolveOwnedSource((int) $request->user()->id, $source);

        return response()->json(
            $this->detailsReadService->forSource($source)
        );
    }

    public function store(Request $request, FinishedProductSpecification $specification): JsonResponse
    {
        $specification = $this->accessService->resolveOwnedFacadeSpecification((int) $request->user()->id, $specification);

        $validated = $request->validate($this->rules());
        $validated['finished_product_specification_id'] = $specification->id;
        $this->accessService->assertOwnedLinkedReferences($validated, (int) $request->user()->id);
        $validated['price_per_m2_normalized'] = $this->resolveNormalizedPrice($validated, true);

        $source = FinishedProductPriceSource::create($validated);
        $this->refreshService->refresh($specification);

        return response()->json($source->fresh(['supplier', 'priceListVersion']), 201);
    }

    public function update(Request $request, FinishedProductPriceSource $source): JsonResponse
    {
        $source = $this->accessService->resolveOwnedSource((int) $request->user()->id, $source);
        $specification = $source->specification;

        $validated = $request->validate($this->rules(true));
        $payload = array_merge($source->toArray(), $validated);
        $this->accessService->assertOwnedLinkedReferences($payload, (int) $request->user()->id);
        $shouldRecompute = array_key_exists('price_per_m2_normalized', $validated)
            || array_key_exists('source_price', $validated)
            || array_key_exists('source_unit', $validated)
            || array_key_exists('conversion_factor_to_m2', $validated);

        if ($shouldRecompute) {
            $validated['price_per_m2_normalized'] = $this->resolveNormalizedPrice($payload, array_key_exists('price_per_m2_normalized', $validated));
        }

        $source->update($validated);
        $this->refreshService->refresh($specification);

        return response()->json($source->fresh(['supplier', 'priceListVersion']));
    }

    public function activate(Request $request, FinishedProductPriceSource $source): JsonResponse
    {
        $source = $this->accessService->resolveOwnedSource((int) $request->user()->id, $source);
        $specification = $source->specification;

        $source->update([
            'status' => FinishedProductPriceSource::STATUS_ACTIVE,
            'stale_reason' => null,
        ]);
        $this->refreshService->refresh($specification);

        return response()->json($source->fresh(['supplier', 'priceListVersion']));
    }

    public function deactivate(Request $request, FinishedProductPriceSource $source): JsonResponse
    {
        $source = $this->accessService->resolveOwnedSource((int) $request->user()->id, $source);
        $specification = $source->specification;

        $source->update([
            'status' => FinishedProductPriceSource::STATUS_INACTIVE,
        ]);
        $this->refreshService->refresh($specification);

        return response()->json($source->fresh(['supplier', 'priceListVersion']));
    }

    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return [
            'supplier_id' => 'nullable|exists:suppliers,id',
            'source_kind' => [$required, 'in:price_list_row,price_document,url_capture,manual_entry'],
            'price_list_version_id' => 'nullable|exists:price_list_versions,id',
            'source_price' => [$required, 'numeric', 'min:0.01'],
            'source_unit' => 'nullable|string|max:50',
            'conversion_factor_to_m2' => 'nullable|numeric|min:0.000001',
            'price_per_m2_normalized' => 'nullable|numeric|min:0.01',
            'captured_at' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'article' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'nullable|in:active,inactive,stale,invalid,superseded',
            'stale_reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'metadata' => 'nullable|array',
        ];
    }

    private function resolveNormalizedPrice(array $payload, bool $preferExplicit = false): ?float
    {
        if ($preferExplicit && !empty($payload['price_per_m2_normalized'])) {
            return round((float) $payload['price_per_m2_normalized'], 4);
        }

        if (!isset($payload['source_price'])) {
            return null;
        }

        $sourcePrice = (float) $payload['source_price'];
        $unit = mb_strtolower(trim((string) ($payload['source_unit'] ?? 'м²')), 'UTF-8');

        if (in_array($unit, ['м²', 'м2', 'm2', ''], true)) {
            return round($sourcePrice, 4);
        }

        $factor = isset($payload['conversion_factor_to_m2'])
            ? (float) $payload['conversion_factor_to_m2']
            : 0.0;

        if ($factor <= 0) {
            return null;
        }

        return round($sourcePrice / $factor, 4);
    }
}
