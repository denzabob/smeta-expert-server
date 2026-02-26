<?php

namespace App\Services\PriceImport;

use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use App\Models\SupplierProductAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for importing facade prices from price lists.
 *
 * Flow per row:
 *   A) Find or create Material(type=facade) by dedup key
 *   B) Create/update SupplierProductAlias (SKU → material_id)
 *   C) Upsert MaterialPrice for the price_list_version
 */
class FacadePriceImportService
{
    /**
     * Import a batch of facade price rows.
     *
     * @param int $priceListVersionId
     * @param int $supplierId
     * @param array $rows Each row should have:
     *   - external_key (string, required) — SKU / артикул
     *   - external_name (string, required) — наименование из прайса
     *   - price_per_m2 (float, required)
     *   - currency (string, default 'RUB')
     *   - thickness_mm (int, required)
     *   - finish_type (string, required) — pvc_film|plastic|enamel|veneer|solid_wood|aluminum_frame|other
     *   - finish_name (string, required) — человекочитаемое: "ПВХ", "Эмаль"
     *   - decor (string, required) — декор: "Сантьяго SF 022"
     *   - collection (string, optional) — "Standart", "Premium"
     *   - base_material (string, default 'mdf')
     *   - price_group (string, optional) — группа плёнки 1..5
     *   - finish_variant (string, optional) — matte|gloss|metallic|soft_touch|textured
     *   - film_article (string, optional) — внутренний код плёнки
     * @return array Import result stats
     */
    public function import(int $priceListVersionId, int $supplierId, array $rows): array
    {
        $version = PriceListVersion::findOrFail($priceListVersionId);
        $supplier = Supplier::findOrFail($supplierId);

        $stats = [
            'created_materials' => 0,
            'updated_materials' => 0,
            'created_aliases' => 0,
            'updated_aliases' => 0,
            'created_prices' => 0,
            'updated_prices' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            foreach ($rows as $index => $row) {
                try {
                    $this->processRow($row, $priceListVersionId, $supplierId, $stats, $index);
                } catch (\Exception $e) {
                    $stats['skipped']++;
                    $stats['errors'][] = [
                        'row' => $index + 1,
                        'external_key' => $row['external_key'] ?? null,
                        'reason' => $e->getMessage(),
                    ];

                    Log::warning('FacadePriceImport: row error', [
                        'row' => $index + 1,
                        'error' => $e->getMessage(),
                        'data' => $row,
                    ]);
                }
            }

            DB::commit();

            Log::info('FacadePriceImport completed', [
                'price_list_version_id' => $priceListVersionId,
                'supplier_id' => $supplierId,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * Process a single facade price row.
     */
    private function processRow(array $row, int $priceListVersionId, int $supplierId, array &$stats, int $index): void
    {
        // Validate required fields
        $this->validateRow($row, $index);

        $externalKey = trim($row['external_key']);
        $externalName = trim($row['external_name']);
        $pricePerM2 = (float) $row['price_per_m2'];
        $currency = $row['currency'] ?? 'RUB';
        $thicknessMm = (int) $row['thickness_mm'];
        $finishType = trim($row['finish_type']);
        $finishName = trim($row['finish_name']);
        $decor = trim($row['decor'] ?? $finishName);
        $collection = trim($row['collection'] ?? '');
        $baseMaterial = trim($row['base_material'] ?? 'mdf');
        $priceGroup = trim($row['price_group'] ?? '');
        $finishVariant = trim($row['finish_variant'] ?? '');
        $filmArticle = trim($row['film_article'] ?? '');

        // Build spec array for find-or-create
        $spec = [
            'base_material' => $baseMaterial,
            'thickness_mm' => $thicknessMm,
            'finish_type' => $finishType,
            'finish_name' => $finishName,
            'finish_variant' => $finishVariant,
            'collection' => $collection,
            'decor' => $decor,
            'price_group' => $priceGroup,
            'film_article' => $filmArticle,
        ];

        // Step A: Find or create the facade material by spec
        $result = Material::findOrCreateFacadeSpec($spec);
        $material = $result['material'];

        if ($result['created']) {
            $stats['created_materials']++;
        } else {
            $stats['updated_materials']++;
        }

        // Step B: Create/update supplier product alias
        $this->upsertAlias($supplierId, $externalKey, $externalName, $material->id, $stats);

        // Step C: Upsert material price
        $this->upsertPrice($priceListVersionId, $material->id, $supplierId, $pricePerM2, $currency, $index, $stats);
    }

    /**
     * Validate required fields in a row.
     */
    private function validateRow(array $row, int $index): void
    {
        $required = ['external_key', 'external_name', 'price_per_m2', 'thickness_mm', 'finish_type', 'finish_name', 'decor'];

        foreach ($required as $field) {
            if (!isset($row[$field]) || (is_string($row[$field]) && trim($row[$field]) === '')) {
                throw new \InvalidArgumentException(
                    "Row " . ($index + 1) . ": missing required field '{$field}'"
                );
            }
        }

        if ((float) $row['price_per_m2'] <= 0) {
            throw new \InvalidArgumentException(
                "Row " . ($index + 1) . ": price_per_m2 must be > 0"
            );
        }

        if ((int) $row['thickness_mm'] <= 0) {
            throw new \InvalidArgumentException(
                "Row " . ($index + 1) . ": thickness_mm must be > 0"
            );
        }

        if (!in_array($row['finish_type'], Material::FINISH_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Row " . ($index + 1) . ": invalid finish_type '{$row['finish_type']}'. Allowed: " . implode(', ', Material::FINISH_TYPES)
            );
        }
    }

    /**
     * Create or update supplier product alias.
     */
    private function upsertAlias(int $supplierId, string $externalKey, string $externalName, int $materialId, array &$stats): void
    {
        $alias = SupplierProductAlias::where('supplier_id', $supplierId)
            ->where('external_key', $externalKey)
            ->where('internal_item_type', SupplierProductAlias::TYPE_MATERIAL)
            ->first();

        if ($alias) {
            $alias->update([
                'external_name' => $externalName,
                'internal_item_id' => $materialId,
                'supplier_unit' => 'м²',
                'internal_unit' => 'м²',
                'conversion_factor' => 1.0,
                'price_transform' => SupplierProductAlias::TRANSFORM_NONE,
                'last_seen_at' => now(),
            ]);
            $alias->increment('usage_count');
            $stats['updated_aliases']++;
        } else {
            SupplierProductAlias::create([
                'supplier_id' => $supplierId,
                'external_key' => $externalKey,
                'external_name' => $externalName,
                'internal_item_type' => SupplierProductAlias::TYPE_MATERIAL,
                'internal_item_id' => $materialId,
                'supplier_unit' => 'м²',
                'internal_unit' => 'м²',
                'conversion_factor' => 1.0,
                'price_transform' => SupplierProductAlias::TRANSFORM_NONE,
                'confidence' => SupplierProductAlias::CONFIDENCE_AUTO_EXACT,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
                'usage_count' => 1,
            ]);
            $stats['created_aliases']++;
        }
    }

    /**
     * Upsert material price for a version.
     */
    private function upsertPrice(
        int $priceListVersionId,
        int $materialId,
        int $supplierId,
        float $pricePerM2,
        string $currency,
        int $rowIndex,
        array &$stats
    ): void {
        $price = MaterialPrice::where('price_list_version_id', $priceListVersionId)
            ->where('material_id', $materialId)
            ->first();

        $data = [
            'price_list_version_id' => $priceListVersionId,
            'material_id' => $materialId,
            'supplier_id' => $supplierId,
            'source_price' => $pricePerM2,
            'source_unit' => 'м²',
            'conversion_factor' => 1.0,
            'price_per_internal_unit' => $pricePerM2,
            'currency' => $currency,
            'source_row_index' => $rowIndex,
        ];

        if ($price) {
            $price->update($data);
            $stats['updated_prices']++;
        } else {
            MaterialPrice::create($data);
            $stats['created_prices']++;
        }
    }
}
