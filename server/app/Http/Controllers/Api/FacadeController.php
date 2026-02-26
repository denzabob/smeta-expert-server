<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Services\FacadeService;
use App\Services\FacadeQuoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD controller for canonical facade materials and their quotes.
 *
 * Routes:
 *   GET    /api/facades              - list with filters/sort/pagination
 *   POST   /api/facades              - create
 *   GET    /api/facades/{id}         - show
 *   PUT    /api/facades/{id}         - update
 *   DELETE /api/facades/{id}         - soft-delete
 *   GET    /api/facades/{id}/quotes  - list quotes for a facade
 *
 *   POST   /api/facade-quotes                 - create quote
 *   POST   /api/facade-quotes/{id}/duplicate  - duplicate quote
 *   POST   /api/facade-quotes/{id}/revalidate - revalidate quote
 *
 *   GET    /api/facade-quotes/similar?material_id=...&mode=strict|extended
 *   GET    /api/facades/filter-options
 */
class FacadeController extends Controller
{
    public function __construct(
        private FacadeService $facadeService,
        private FacadeQuoteService $quoteService,
    ) {}

    /**
     * GET /api/facades
     */
    public function index(Request $request)
    {
        $filters = $request->only([
            'base_type', 'thickness_mm', 'covering', 'cover_type',
            'facade_class', 'collection', 'is_active', 'search',
        ]);

        $sortBy = $request->get('sort_by', 'name');
        $sortDir = $request->get('sort_dir', 'asc');
        $perPage = min((int) $request->get('per_page', 50), 200);

        $paginator = $this->facadeService->list($filters, $sortBy, $sortDir, $perPage);

        return response()->json($paginator);
    }

    /**
     * POST /api/facades
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
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

        $material = $this->facadeService->create($validated);

        return response()->json($material, 201);
    }

    /**
     * GET /api/facades/{id}
     */
    public function show(int $id)
    {
        $material = Material::where('type', Material::TYPE_FACADE)
            ->withCount('prices as quotes_count')
            ->findOrFail($id);

        $quotes = $this->quoteService->getQuotes($id);

        return response()->json([
            'facade' => $material,
            'quotes' => $quotes,
        ]);
    }

    /**
     * PUT /api/facades/{id}
     */
    public function update(Request $request, int $id)
    {
        $material = Material::where('type', Material::TYPE_FACADE)->findOrFail($id);

        $validated = $request->validate([
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

        $material = $this->facadeService->update($material, $validated);

        return response()->json($material);
    }

    /**
     * DELETE /api/facades/{id}
     */
    public function destroy(int $id)
    {
        $material = Material::where('type', Material::TYPE_FACADE)->findOrFail($id);
        $result = $this->facadeService->delete($material);

        return response()->json($result);
    }

    /**
     * GET /api/facades/{id}/quotes
     */
    public function quotes(int $id)
    {
        Material::where('type', Material::TYPE_FACADE)->findOrFail($id);
        $quotes = $this->quoteService->getQuotes($id);

        return response()->json([
            'material_id' => $id,
            'quotes' => $quotes,
            'count' => count($quotes),
        ]);
    }

    /**
     * POST /api/facade-quotes
     */
    public function storeQuote(Request $request)
    {
        $validated = $request->validate([
            'material_id' => 'required|exists:materials,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'price_list_version_id' => 'required|exists:price_list_versions,id',
            'source_price' => 'required|numeric|min:0.01',
            'source_unit' => 'nullable|string|max:20',
            'conversion_factor' => 'nullable|numeric|min:0.001',
            'price_per_internal_unit' => 'nullable|numeric|min:0.01',
            'article' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'source_row_index' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|max:5',
        ]);

        // Verify material is facade type
        $material = Material::find($validated['material_id']);
        if (!$material || $material->type !== Material::TYPE_FACADE) {
            return response()->json(['message' => 'material_id must reference a facade-type material.'], 422);
        }

        // Unit safeguard: if source_unit != м² then conversion_factor is mandatory
        $sourceUnit = $validated['source_unit'] ?? 'м²';
        $conversionFactor = $validated['conversion_factor'] ?? 1.0;
        if ($sourceUnit !== 'м²' && (float) $conversionFactor === 1.0) {
            return response()->json([
                'message' => "source_unit='{$sourceUnit}' requires a non-1.0 conversion_factor to convert to м².",
            ], 422);
        }

        $quote = $this->quoteService->createQuote($validated);
        $quote->load(['priceListVersion.priceList.supplier', 'material']);

        return response()->json($quote, 201);
    }

    /**
     * POST /api/facade-quotes/{id}/duplicate
     */
    public function duplicateQuote(Request $request, int $id)
    {
        $quote = MaterialPrice::findOrFail($id);

        $validated = $request->validate([
            'target_material_id' => 'nullable|exists:materials,id',
            'new_facade_class' => ['nullable', Rule::in(Material::FACADE_CLASSES)],
        ]);

        if (empty($validated['target_material_id']) && empty($validated['new_facade_class'])) {
            return response()->json(['message' => 'Either target_material_id or new_facade_class is required.'], 422);
        }

        $result = $this->quoteService->duplicateQuote($quote, $validated);

        return response()->json($result, 201);
    }

    /**
     * POST /api/facade-quotes/{id}/revalidate
     */
    public function revalidateQuote(Request $request, int $id)
    {
        $quote = MaterialPrice::findOrFail($id);

        $validated = $request->validate([
            'new_price' => 'nullable|numeric|min:0.01',
        ]);

        $result = $this->quoteService->revalidateQuote($quote, $validated['new_price'] ?? null);

        return response()->json($result, 201);
    }

    /**
     * PUT /api/facade-quotes/{id}
     */
    public function updateQuote(Request $request, int $id)
    {
        $quote = MaterialPrice::findOrFail($id);

        $validated = $request->validate([
            'source_price' => 'nullable|numeric|min:0.01',
            'source_unit' => 'nullable|string|max:20',
            'conversion_factor' => 'nullable|numeric|min:0.001',
            'article' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        if (isset($validated['source_price'])) {
            $sourceUnit = $validated['source_unit'] ?? $quote->source_unit ?? 'м²';
            $conversionFactor = $validated['conversion_factor'] ?? $quote->conversion_factor ?? 1.0;
            $validated['price_per_m2'] = round($validated['source_price'] * (float) $conversionFactor, 4);
        }

        $quote->update($validated);
        $quote->load(['priceListVersion.priceList.supplier', 'material']);

        return response()->json($quote);
    }

    /**
     * DELETE /api/facade-quotes/{id}
     */
    public function deleteQuote(int $id)
    {
        $quote = MaterialPrice::findOrFail($id);
        $quote->delete();

        return response()->json(['message' => 'Котировка удалена']);
    }

    /**
     * GET /api/facade-quotes/similar?material_id=...&mode=strict|extended
     */
    public function similarQuotes(Request $request)
    {
        $validated = $request->validate([
            'material_id' => 'required|exists:materials,id',
            'mode' => 'nullable|in:strict,extended',
        ]);

        $material = Material::where('type', Material::TYPE_FACADE)->findOrFail($validated['material_id']);
        $mode = $validated['mode'] ?? 'strict';

        $quotes = $this->facadeService->findSimilarQuotes($material, $mode);

        return response()->json([
            'material_id' => $material->id,
            'material_name' => $material->name,
            'mode' => $mode,
            'quotes' => $quotes,
            'count' => count($quotes),
        ]);
    }

    /**
     * GET /api/facades/filter-options
     */
    public function filterOptions()
    {
        return response()->json($this->facadeService->getFilterOptions());
    }
}
