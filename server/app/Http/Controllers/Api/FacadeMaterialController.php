<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceListVersion;
use Illuminate\Http\Request;

/**
 * API controller for facade materials — used by the position editor
 * to select a facade from price lists.
 */
class FacadeMaterialController extends Controller
{
    /**
     * GET /api/facade-materials
     * 
     * List facade materials with prices from a specific price list version.
     * Requires price_list_version_id — deterministic selection.
     * Supports filters: thickness_mm, finish_type, search, supplier_id
     */
    public function index(Request $request)
    {
        $request->validate([
            'price_list_version_id' => 'nullable|exists:price_list_versions,id',
        ]);

        $versionId = $request->filled('price_list_version_id')
            ? (int) $request->price_list_version_id
            : null;

        $query = Material::where('type', Material::TYPE_FACADE);

        // Filter by is_active (default: show all; pass is_active=1 to show active only)
        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->is_active);
        }

        // Filter by thickness
        if ($request->filled('thickness_mm')) {
            $query->where('thickness_mm', (int) $request->thickness_mm);
        }

        // Filter by finish_type (from metadata finish.type)
        if ($request->filled('finish_type')) {
            $ft = $request->finish_type;
            $query->whereJsonContains('metadata->finish->type', $ft);
        }

        // Search by name, finish_name, finish_code
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', $search)
                  ->orWhere('search_name', 'LIKE', $search)
                  ->orWhere('article', 'LIKE', $search);
            });
        }

        // Filter by supplier (via aliases)
        if ($request->filled('supplier_id')) {
            $supplierId = (int) $request->supplier_id;
            $query->whereHas('aliases', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            });
        }

        // If version is specified, only show materials that have prices in that version
        if ($versionId) {
            $query->whereHas('prices', function ($q) use ($versionId) {
                $q->where('price_list_version_id', $versionId);
            });
        }

        $facades = $query->orderBy('name')->limit(100)->get();

        // Enrich with price data
        $result = $facades->map(function (Material $material) use ($versionId) {
            $price = null;

            if ($versionId) {
                // Deterministic price from the specified version
                $price = MaterialPrice::where('material_id', $material->id)
                    ->where('price_list_version_id', $versionId)
                    ->first();
            } else {
                // Try to find a price from any active version
                $price = MaterialPrice::where('material_id', $material->id)
                    ->whereHas('priceListVersion', function ($q) {
                        $q->where('status', PriceListVersion::STATUS_ACTIVE);
                    })
                    ->orderByDesc('id')
                    ->first();
            }

            $metadata = $material->metadata ?? [];

            // Fallback: use material.price_per_unit when no material_prices record exists
            $pricePerM2 = $price
                ? (float) $price->price_per_internal_unit
                : (float) ($material->price_per_unit ?? 0);

            // Read spec via unified getter (handles legacy metadata formats)
            $spec = $material->getFacadeSpec();

            return [
                'id' => $material->id,
                'name' => $material->name,
                'article' => $material->article,
                'thickness_mm' => $material->thickness_mm,
                'base_material' => $spec['base_material'],
                'finish_type' => $spec['finish_type'],
                'finish_name' => $spec['finish_name'],
                'finish_variant' => $spec['finish_variant'],
                'collection' => $spec['collection'],
                'decor' => $spec['decor'],
                'price_group' => $spec['price_group'],
                'film_article' => $spec['film_article'],
                'unit' => $material->unit,
                // Price data
                'price_id' => $price?->id,
                'price_per_m2' => $pricePerM2,
                'currency' => $price?->currency ?? 'RUB',
                'price_list_version_id' => $price?->price_list_version_id ?? $versionId,
                'supplier_id' => $price?->supplier_id,
                'price_source' => $price ? 'price_list' : 'manual',
                'spec' => $spec,
            ];
        });

        return response()->json([
            'data' => $result->values(),
            'meta' => [
                'total' => $result->count(),
                'filters' => [
                    'thickness_options' => Material::facades()
                        ->whereNotNull('thickness_mm')
                        ->distinct()
                        ->pluck('thickness_mm')
                        ->sort()
                        ->values(),
                    'finish_types' => Material::FINISH_TYPES,
                ],
            ],
        ]);
    }

    /**
     * GET /api/facade-materials/{id}
     *
     * Get a single facade material with price from a specific version.
     * Accepts optional price_list_version_id; falls back to any active version.
     */
    public function show(Request $request, int $id)
    {
        $material = Material::where('type', Material::TYPE_FACADE)->findOrFail($id);

        $priceQuery = MaterialPrice::where('material_id', $material->id);

        if ($request->filled('price_list_version_id')) {
            $priceQuery->where('price_list_version_id', (int) $request->price_list_version_id);
        } else {
            $priceQuery->whereHas('priceListVersion', function ($q) {
                $q->where('status', PriceListVersion::STATUS_ACTIVE);
            });
        }

        $price = $priceQuery->orderByDesc('id')->first();

        $spec = $material->getFacadeSpec();

        return response()->json([
            'id' => $material->id,
            'name' => $material->name,
            'article' => $material->article,
            'thickness_mm' => $material->thickness_mm,
            'base_material' => $spec['base_material'],
            'finish_type' => $spec['finish_type'],
            'finish_name' => $spec['finish_name'],
            'finish_variant' => $spec['finish_variant'],
            'collection' => $spec['collection'],
            'decor' => $spec['decor'],
            'price_group' => $spec['price_group'],
            'film_article' => $spec['film_article'],
            'unit' => $material->unit,
            'price_id' => $price?->id,
            'price_per_m2' => $price ? (float) $price->price_per_internal_unit : null,
            'currency' => $price?->currency ?? 'RUB',
            'price_list_version_id' => $price?->price_list_version_id,
            'supplier_id' => $price?->supplier_id,
            'spec' => $spec,
        ]);
    }

    /**
     * POST /api/facade-materials/import-prices
     *
     * Import facade prices from structured data.
     * Body: { price_list_version_id, supplier_id, rows: [...] }
     */
    public function importPrices(Request $request)
    {
        $validated = $request->validate([
            'price_list_version_id' => 'required|exists:price_list_versions,id',
            'supplier_id' => 'required|exists:suppliers,id',
            'rows' => 'required|array|min:1',
            'rows.*.external_key' => 'required|string',
            'rows.*.external_name' => 'required|string',
            'rows.*.price_per_m2' => 'required|numeric|min:0.01',
            'rows.*.thickness_mm' => 'required|integer|min:1',
            'rows.*.finish_type' => 'required|string',
            'rows.*.finish_name' => 'required|string',
            'rows.*.decor' => 'required|string',
            'rows.*.collection' => 'nullable|string',
            'rows.*.base_material' => 'nullable|string',
            'rows.*.currency' => 'nullable|string|size:3',
            'rows.*.price_group' => 'nullable|string|max:10',
            'rows.*.finish_variant' => 'nullable|string|max:50',
            'rows.*.film_article' => 'nullable|string|max:100',
        ]);

        $service = app(\App\Services\PriceImport\FacadePriceImportService::class);

        $result = $service->import(
            $validated['price_list_version_id'],
            $validated['supplier_id'],
            $validated['rows']
        );

        return response()->json($result);
    }

    /**
     * GET /api/facade-materials/spec-constants
     *
     * Return all enum/constant values for facade spec fields
     * so the frontend can render dropdowns.
     */
    public function specConstants()
    {
        return response()->json([
            'finish_types' => collect(Material::FINISH_TYPES)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::FINISH_LABELS[$v] ?? $v,
            ]),
            'base_materials' => collect(Material::BASE_MATERIALS)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::BASE_MATERIAL_LABELS[$v] ?? $v,
            ]),
            'price_groups' => Material::PRICE_GROUPS,
            'finish_variants' => collect(Material::FINISH_VARIANTS)->map(fn ($v) => [
                'value' => $v,
                'label' => Material::FINISH_VARIANT_LABELS[$v] ?? $v,
            ]),
        ]);
    }
}
