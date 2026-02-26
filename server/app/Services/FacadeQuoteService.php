<?php

namespace App\Services;

use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use App\Models\SupplierProductAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing facade price quotes (material_prices for facades).
 * Handles listing, creation, duplication, and revalidation of quotes.
 */
class FacadeQuoteService
{
    /**
     * Get all quotes for a canonical facade, enriched with supplier/version info.
     */
    public function getQuotes(int $materialId): array
    {
        $quotes = MaterialPrice::where('material_id', $materialId)
            ->with([
                'priceListVersion' => function ($q) {
                    $q->with('priceList.supplier');
                },
                'supplier',
            ])
            ->get()
            ->sortByDesc(function ($mp) {
                return $mp->priceListVersion?->captured_at;
            });

        return $quotes->map(function (MaterialPrice $mp) {
            $version = $mp->priceListVersion;
            $priceList = $version?->priceList;
            $supplier = $mp->supplier ?? $priceList?->supplier;

            return [
                'id' => $mp->id,
                'material_price_id' => $mp->id,
                'material_id' => $mp->material_id,
                'price_list_version_id' => $mp->price_list_version_id,
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->name ?? '—',
                'price_per_m2' => (float) $mp->price_per_internal_unit,
                'source_price' => (float) $mp->source_price,
                'currency' => $mp->currency ?? 'RUB',
                'article' => $mp->article,
                'category' => $mp->category,
                'description' => $mp->description,
                'source_row_index' => $mp->source_row_index,
                'thickness' => $mp->thickness,
                // Version info
                'price_list_name' => $priceList?->name ?? '—',
                'version_number' => $version?->version_number,
                'captured_at' => $version?->captured_at?->toISOString(),
                'effective_date' => $version?->effective_date?->format('Y-m-d'),
                'source_type' => $version?->source_type,
                'source_url' => $version?->source_url,
                'original_filename' => $version?->original_filename,
            ];
        })->values()->toArray();
    }

    /**
     * Create a new quote for a facade.
     * Also persists a SupplierProductAlias for future import re-matching.
     */
    public function createQuote(array $data): MaterialPrice
    {
        $material = Material::where('type', Material::TYPE_FACADE)->findOrFail($data['material_id']);

        // Unit safeguard: facades MUST be in м²
        $sourceUnit = $data['source_unit'] ?? 'м²';
        $conversionFactor = (float) ($data['conversion_factor'] ?? 1.0);

        if ($sourceUnit !== 'м²' && $conversionFactor == 1.0) {
            throw new \InvalidArgumentException(
                "source_unit='{$sourceUnit}' requires a conversion_factor != 1.0 to convert to м²."
            );
        }

        $data['source_unit'] = $sourceUnit;
        $data['conversion_factor'] = $conversionFactor;
        $data['price_per_internal_unit'] = $data['price_per_internal_unit']
            ?? ($data['source_price'] / ($conversionFactor ?: 1.0));

        return DB::transaction(function () use ($data, $material) {
            $quote = MaterialPrice::create($data);

            // Persist alias for future import matching (SKU → canonical facade)
            $this->persistAlias($data, $material);

            return $quote;
        });
    }

    /**
     * Save or update a SupplierProductAlias linking the supplier's SKU to this facade material.
     */
    private function persistAlias(array $data, Material $material): void
    {
        $supplierId = $data['supplier_id'] ?? null;
        $article = $data['article'] ?? null;

        if (!$supplierId || !$article) {
            return; // No alias without supplier + article
        }

        $externalKey = SupplierProductAlias::generateExternalKey($article);

        $alias = SupplierProductAlias::findByExternalKey(
            $supplierId,
            $externalKey,
            SupplierProductAlias::TYPE_MATERIAL
        );

        if ($alias) {
            // Update existing: re-map to this material, refresh usage
            $alias->internal_item_id = $material->id;
            $alias->external_name = $article;
            $alias->supplier_unit = $data['source_unit'] ?? 'м²';
            $alias->internal_unit = 'м²';
            $alias->conversion_factor = $data['conversion_factor'] ?? 1.0;
            $alias->confidence = SupplierProductAlias::CONFIDENCE_MANUAL;
            $alias->recordUsage();
        } else {
            SupplierProductAlias::create([
                'supplier_id' => $supplierId,
                'external_key' => $externalKey,
                'external_name' => $article,
                'internal_item_type' => SupplierProductAlias::TYPE_MATERIAL,
                'internal_item_id' => $material->id,
                'supplier_unit' => $data['source_unit'] ?? 'м²',
                'internal_unit' => 'м²',
                'conversion_factor' => $data['conversion_factor'] ?? 1.0,
                'price_transform' => SupplierProductAlias::TRANSFORM_DIVIDE,
                'confidence' => SupplierProductAlias::CONFIDENCE_MANUAL,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'usage_count' => 1,
            ]);
        }
    }

    /**
     * Duplicate a quote:
     * - Option A: copy to a different canonical facade (target_material_id)
     * - Option B: clone current facade with a different facade_class, create quote for clone
     */
    public function duplicateQuote(MaterialPrice $quote, array $options): array
    {
        $targetMaterialId = $options['target_material_id'] ?? null;
        $newFacadeClass = $options['new_facade_class'] ?? null;

        return DB::transaction(function () use ($quote, $targetMaterialId, $newFacadeClass) {
            $createdMaterial = null;

            if ($newFacadeClass) {
                // Clone the source facade material with a different class
                $sourceMaterial = Material::findOrFail($quote->material_id);
                $facadeService = app(FacadeService::class);

                $cloneData = $sourceMaterial->toArray();
                unset($cloneData['id'], $cloneData['created_at'], $cloneData['updated_at']);
                $cloneData['facade_class'] = $newFacadeClass;
                $cloneData['article'] = null; // will be regenerated
                $cloneData['name'] = null; // will be regenerated via auto_name
                $cloneData['auto_name'] = true;

                $createdMaterial = $facadeService->create($cloneData);
                $targetMaterialId = $createdMaterial->id;
            }

            if (!$targetMaterialId) {
                throw new \InvalidArgumentException('Either target_material_id or new_facade_class is required.');
            }

            // Create the new quote
            $newQuote = MaterialPrice::create([
                'price_list_version_id' => $quote->price_list_version_id,
                'material_id' => $targetMaterialId,
                'supplier_id' => $quote->supplier_id,
                'source_price' => $quote->source_price,
                'source_unit' => $quote->source_unit,
                'conversion_factor' => $quote->conversion_factor,
                'price_per_internal_unit' => $quote->price_per_internal_unit,
                'currency' => $quote->currency,
                'article' => $quote->article,
                'category' => $quote->category,
                'description' => $quote->description,
                'source_row_index' => $quote->source_row_index,
                'thickness' => $quote->thickness,
            ]);

            return [
                'quote' => $newQuote,
                'created_material' => $createdMaterial,
            ];
        });
    }

    /**
     * Revalidate a quote: create a new price_list_version and a new quote
     * mirroring the old one, optionally with an updated price.
     */
    public function revalidateQuote(MaterialPrice $quote, ?float $newPrice = null): array
    {
        return DB::transaction(function () use ($quote, $newPrice) {
            $oldVersion = PriceListVersion::findOrFail($quote->price_list_version_id);
            $priceList = PriceList::findOrFail($oldVersion->price_list_id);

            // Create new version (sha256=NULL allowed for revalidation - MySQL permits multiple NULLs in UNIQUE)
            $newVersionNumber = $priceList->getNextVersionNumber();

            $newVersion = PriceListVersion::create([
                'price_list_id' => $priceList->id,
                'version_number' => $newVersionNumber,
                'sha256' => null,
                'currency' => $oldVersion->currency,
                'effective_date' => now(),
                'captured_at' => now(),
                'file_path' => $oldVersion->file_path,
                'storage_disk' => $oldVersion->storage_disk,
                'original_filename' => $oldVersion->original_filename,
                'source_type' => $oldVersion->source_type,
                'source_url' => $oldVersion->source_url,
                'status' => PriceListVersion::STATUS_ACTIVE,
                'metadata' => array_merge(
                    $oldVersion->metadata ?? [],
                    ['revalidated_from' => $oldVersion->id]
                ),
                'notes' => 'Revalidated from version #' . $oldVersion->version_number,
            ]);

            $finalPrice = $newPrice ?? (float) $quote->source_price;

            // Create new quote
            $newQuote = MaterialPrice::create([
                'price_list_version_id' => $newVersion->id,
                'material_id' => $quote->material_id,
                'supplier_id' => $quote->supplier_id,
                'source_price' => $finalPrice,
                'source_unit' => $quote->source_unit,
                'conversion_factor' => $quote->conversion_factor ?? 1.0,
                'price_per_internal_unit' => $finalPrice / ($quote->conversion_factor ?: 1.0),
                'currency' => $quote->currency,
                'article' => $quote->article,
                'category' => $quote->category,
                'description' => $quote->description,
                'source_row_index' => $quote->source_row_index,
                'thickness' => $quote->thickness,
            ]);

            Log::info('FacadeQuoteService: revalidated', [
                'old_quote_id' => $quote->id,
                'new_quote_id' => $newQuote->id,
                'old_version_id' => $oldVersion->id,
                'new_version_id' => $newVersion->id,
                'price_changed' => $newPrice !== null,
            ]);

            return [
                'new_quote' => $newQuote,
                'new_version' => $newVersion,
                'old_quote_id' => $quote->id,
                'old_version_id' => $oldVersion->id,
            ];
        });
    }
}
