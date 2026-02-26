<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Services\FacadeQuoteService;
use App\Services\FacadeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unified Finished Products API.
 *
 * Current supported subtype:
 * - facade
 *
 * This controller is an API compatibility layer for future finished-product
 * types while keeping existing facade business logic unchanged.
 */
class FinishedProductController extends Controller
{
    public function __construct(
        private FacadeService $facadeService,
        private FacadeQuoteService $quoteService,
    ) {}

    public function filterOptions(): JsonResponse
    {
        return response()->json([
            'product_types' => [
                ['value' => 'facade', 'label' => 'Фасады'],
            ],
            'facade' => $this->facadeService->getFilterOptions(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $productType = $request->input('product_type', 'facade');
        $this->ensureSupportedProductType($productType);

        $filters = $request->only([
            'base_type', 'thickness_mm', 'covering', 'cover_type',
            'facade_class', 'collection', 'is_active', 'search',
        ]);

        $sortBy = $request->get('sort_by', 'name');
        $sortDir = $request->get('sort_dir', 'asc');
        $perPage = min((int) $request->get('per_page', 50), 200);

        $paginator = $this->facadeService->list($filters, $sortBy, $sortDir, $perPage);

        // annotate payload with generic product type
        $paginator->getCollection()->transform(function ($item) use ($productType) {
            $item->product_type = $productType;
            return $item;
        });

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $productType = $request->input('product_type', 'facade');
        $this->ensureSupportedProductType($productType);

        $validated = $request->validate([
            'product_type' => 'sometimes|in:facade',
            'name' => 'nullable|string|max:500',
            'auto_name' => 'nullable|boolean',
            'facade_class' => ['required', Rule::in(Material::FACADE_CLASSES)],
            'facade_base_type' => ['required', Rule::in(Material::BASE_MATERIALS)],
            'facade_thickness_mm' => 'required|integer|min:1',
            'facade_covering' => ['required', Rule::in(Material::FINISH_TYPES)],
            'facade_cover_type' => ['nullable', Rule::in(Material::FINISH_VARIANTS)],
            'facade_collection' => 'nullable|string|max:100',
            'facade_price_group_label' => 'nullable|string|max:50',
            'facade_decor_label' => 'nullable|string|max:255',
            'facade_article_optional' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        unset($validated['product_type']);
        $product = $this->facadeService->create($validated);
        $product->product_type = $productType;

        return response()->json($product, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $productType = $request->input('product_type', 'facade');
        $this->ensureSupportedProductType($productType);

        $product = Material::where('type', Material::TYPE_FACADE)
            ->withCount('prices as quotes_count')
            ->findOrFail($id);

        $quotes = $this->quoteService->getQuotes($id);

        return response()->json([
            'product_type' => $productType,
            'product' => $product,
            'quotes' => $quotes,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $productType = $request->input('product_type', 'facade');
        $this->ensureSupportedProductType($productType);

        $product = Material::where('type', Material::TYPE_FACADE)->findOrFail($id);

        $validated = $request->validate([
            'product_type' => 'sometimes|in:facade',
            'name' => 'nullable|string|max:500',
            'auto_name' => 'nullable|boolean',
            'facade_class' => ['nullable', Rule::in(Material::FACADE_CLASSES)],
            'facade_base_type' => ['nullable', Rule::in(Material::BASE_MATERIALS)],
            'facade_thickness_mm' => 'nullable|integer|min:1',
            'facade_covering' => ['nullable', Rule::in(Material::FINISH_TYPES)],
            'facade_cover_type' => ['nullable', Rule::in(Material::FINISH_VARIANTS)],
            'facade_collection' => 'nullable|string|max:100',
            'facade_price_group_label' => 'nullable|string|max:50',
            'facade_decor_label' => 'nullable|string|max:255',
            'facade_article_optional' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        unset($validated['product_type']);
        $updated = $this->facadeService->update($product, $validated);
        $updated->product_type = $productType;

        return response()->json($updated);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $productType = $request->input('product_type', 'facade');
        $this->ensureSupportedProductType($productType);

        $product = Material::where('type', Material::TYPE_FACADE)->findOrFail($id);
        $result = $this->facadeService->delete($product);

        return response()->json(array_merge($result, [
            'product_type' => $productType,
        ]));
    }

    public function quotes(Request $request, int $id): JsonResponse
    {
        $productType = $request->input('product_type', 'facade');
        $this->ensureSupportedProductType($productType);

        Material::where('type', Material::TYPE_FACADE)->findOrFail($id);
        $quotes = $this->quoteService->getQuotes($id);

        return response()->json([
            'product_type' => $productType,
            'product_id' => $id,
            'quotes' => $quotes,
            'count' => count($quotes),
        ]);
    }

    private function ensureSupportedProductType(string $productType): void
    {
        if ($productType !== 'facade') {
            abort(422, "Unsupported product_type '{$productType}'.");
        }
    }
}

