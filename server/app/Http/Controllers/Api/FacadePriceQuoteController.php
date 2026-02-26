<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceListVersion;
use Illuminate\Http\Request;

/**
 * POST /api/facade-price-quotes
 *
 * Returns available price quotes for a facade material across selected price_list_versions.
 * Deterministic: no "guess latest active" logic. User must supply version IDs.
 */
class FacadePriceQuoteController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'material_id' => 'required|exists:materials,id',
            'price_list_version_ids' => 'required|array|min:1|max:10',
            'price_list_version_ids.*' => 'integer|exists:price_list_versions,id',
        ]);

        $material = Material::where('type', Material::TYPE_FACADE)
            ->findOrFail($validated['material_id']);

        $versionIds = $validated['price_list_version_ids'];

        $prices = MaterialPrice::where('material_id', $material->id)
            ->whereIn('price_list_version_id', $versionIds)
            ->with(['priceListVersion.priceList.supplier'])
            ->get();

        $quotes = $prices->map(function (MaterialPrice $price) {
            $version = $price->priceListVersion;
            $priceList = $version?->priceList;

            $supplier = $priceList?->supplier;

            return [
                'material_price_id' => $price->id,
                'price_list_version_id' => $price->price_list_version_id,
                'supplier_id' => $price->supplier_id ?? $supplier?->id,
                'supplier_name' => $supplier?->name ?? '—',
                'price_per_m2' => (float) $price->price_per_internal_unit,
                'currency' => $price->currency ?? 'RUB',
                'source' => [
                    'price_list_name' => $priceList?->name ?? '—',
                    'version_number' => $version?->version_number,
                    'source_type' => $version?->source_type,
                    'source_url' => $version?->source_url,
                    'original_filename' => $version?->original_filename,
                    'sha256' => $version?->sha256,
                    'effective_date' => $version?->effective_date?->format('Y-m-d'),
                    'captured_at' => $version?->captured_at?->toISOString(),
                ],
            ];
        });

        return response()->json([
            'material_id' => $material->id,
            'material_name' => $material->name,
            'quotes' => $quotes->values(),
            'count' => $quotes->count(),
        ]);
    }
}
