<?php

namespace App\Services\PriceImport;

use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\PriceListVersion;
use App\Models\Smeta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Сервис для получения цен на операции.
 * 
 * Режимы работы:
 * - MODE_BY_SUPPLIER: цена от конкретного поставщика (из его активной версии прайса)
 * - MODE_MEDIAN: медианная цена по всем активным версиям поставщиков
 * 
 * Важно: operations.cost_per_unit НЕ используется (legacy).
 */
class OperationPriceResolver
{
    public const MODE_BY_SUPPLIER = 'by_supplier';
    public const MODE_MEDIAN = 'median';

    /**
     * Default price mode for calculations.
     */
    protected string $defaultMode = self::MODE_BY_SUPPLIER;

    /**
     * Cache TTL in seconds (5 minutes).
     */
    protected int $cacheTtl = 300;

    /**
     * Price type to use.
     */
    protected string $priceType = OperationPrice::PRICE_TYPE_RETAIL;

    /**
     * Get price for operation.
     * 
     * @param int $operationId Base operation ID
     * @param string|null $mode Price mode (by_supplier or median)
     * @param int|null $supplierId Supplier ID (required for by_supplier mode)
     * @param Smeta|null $smeta Smeta context for supplier detection
     * @return array{price: float, source: string, version_id: int|null, unit: string|null}
     */
    public function getPrice(
        int $operationId,
        ?string $mode = null,
        ?int $supplierId = null,
        ?Smeta $smeta = null
    ): array {
        $mode = $mode ?? $this->defaultMode;

        // If smeta provided and no supplier, try to get supplier from smeta
        if (!$supplierId && $smeta && $mode === self::MODE_BY_SUPPLIER) {
            $supplierId = $smeta->supplier_id ?? null;
        }

        // If still no supplier for by_supplier mode, fallback to median
        if ($mode === self::MODE_BY_SUPPLIER && !$supplierId) {
            $mode = self::MODE_MEDIAN;
        }

        return match ($mode) {
            self::MODE_BY_SUPPLIER => $this->getPriceBySupplier($operationId, $supplierId),
            self::MODE_MEDIAN => $this->getMedianPrice($operationId),
            default => $this->getMedianPrice($operationId),
        };
    }

    /**
     * Get price from specific supplier's active version.
     */
    public function getPriceBySupplier(int $operationId, int $supplierId): array
    {
        $cacheKey = "op_price:supplier:{$supplierId}:{$operationId}:{$this->priceType}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($operationId, $supplierId) {
            // Get active version for supplier
            $activeVersion = $this->getActiveVersionForSupplier($supplierId);

            if (!$activeVersion) {
                return $this->noPrice('no_active_version');
            }

            $price = OperationPrice::where('operation_id', $operationId)
                ->where('supplier_id', $supplierId)
                ->where('price_list_version_id', $activeVersion->id)
                ->where('price_type', $this->priceType)
                ->first();

            if (!$price) {
                return $this->noPrice('not_found_for_supplier');
            }

            return [
                'price' => (float) $price->price_per_internal_unit,
                'source' => 'supplier',
                'version_id' => $price->price_list_version_id,
                'supplier_id' => $supplierId,
                'unit' => $price->source_unit ?? $price->operation?->unit,
                'match_confidence' => $price->match_confidence,
            ];
        });
    }

    /**
     * Get median price across all active supplier versions.
     * 
     * Excludes prices where unit doesn't match base operation's unit.
     */
    public function getMedianPrice(int $operationId): array
    {
        $cacheKey = "op_price:median:{$operationId}:{$this->priceType}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($operationId) {
            // Get base operation for unit check
            $operation = Operation::find($operationId);
            if (!$operation) {
                return $this->noPrice('operation_not_found');
            }

            // Get all active versions
            $activeVersionIds = PriceListVersion::where('status', 'active')
                ->pluck('id');

            if ($activeVersionIds->isEmpty()) {
                return $this->noPrice('no_active_versions');
            }

            // Get all prices for this operation from active versions
            $prices = OperationPrice::where('operation_id', $operationId)
                ->whereIn('price_list_version_id', $activeVersionIds)
                ->where('price_type', $this->priceType)
                ->get();

            if ($prices->isEmpty()) {
                return $this->noPrice('no_prices');
            }

            // Filter prices where unit matches (or conversion is possible)
            $validPrices = $prices->filter(function ($price) {
                return $price->canIncludeInMedian();
            });

            if ($validPrices->isEmpty()) {
                return $this->noPrice('no_matching_units');
            }

            // Calculate median
            $priceValues = $validPrices->pluck('price_per_internal_unit')->sort()->values();
            $count = $priceValues->count();
            
            if ($count % 2 === 0) {
                $median = ($priceValues[$count / 2 - 1] + $priceValues[$count / 2]) / 2;
            } else {
                $median = $priceValues[floor($count / 2)];
            }

            return [
                'price' => (float) $median,
                'source' => 'median',
                'version_id' => null,
                'supplier_id' => null,
                'unit' => $operation->unit,
                'suppliers_count' => $validPrices->count(),
                'excluded_count' => $prices->count() - $validPrices->count(),
            ];
        });
    }

    /**
     * Get prices for multiple operations at once (optimized batch).
     * 
     * @param array<int> $operationIds
     * @return array<int, array> Keyed by operation ID
     */
    public function getPricesBatch(
        array $operationIds,
        ?string $mode = null,
        ?int $supplierId = null
    ): array {
        $mode = $mode ?? $this->defaultMode;

        if ($mode === self::MODE_BY_SUPPLIER && $supplierId) {
            return $this->getPricesBySupplierBatch($operationIds, $supplierId);
        }

        return $this->getMedianPricesBatch($operationIds);
    }

    /**
     * Batch get prices from supplier.
     */
    protected function getPricesBySupplierBatch(array $operationIds, int $supplierId): array
    {
        $activeVersion = $this->getActiveVersionForSupplier($supplierId);

        if (!$activeVersion) {
            return collect($operationIds)->mapWithKeys(function ($id) {
                return [$id => $this->noPrice('no_active_version')];
            })->all();
        }

        $prices = OperationPrice::whereIn('operation_id', $operationIds)
            ->where('supplier_id', $supplierId)
            ->where('price_list_version_id', $activeVersion->id)
            ->where('price_type', $this->priceType)
            ->with('operation')
            ->get()
            ->keyBy('operation_id');

        return collect($operationIds)->mapWithKeys(function ($id) use ($prices, $supplierId) {
            $price = $prices->get($id);
            
            if (!$price) {
                return [$id => $this->noPrice('not_found_for_supplier')];
            }

            return [$id => [
                'price' => (float) $price->price_per_internal_unit,
                'source' => 'supplier',
                'version_id' => $price->price_list_version_id,
                'supplier_id' => $supplierId,
                'unit' => $price->source_unit ?? $price->operation?->unit,
                'match_confidence' => $price->match_confidence,
            ]];
        })->all();
    }

    /**
     * Batch get prices from a specific price-list version.
     *
     * This mode is used for project-bound calculations where the project explicitly
     * links the version via project_price_list_versions.
     *
     * @param array<int> $operationIds
     * @param int $priceListVersionId
     * @return array<int, array>
     */
    public function getPricesForVersionBatch(array $operationIds, int $priceListVersionId): array
    {
        if (empty($operationIds)) {
            return [];
        }

        $version = PriceListVersion::query()
            ->with('priceList')
            ->find($priceListVersionId);

        if (!$version || $version->status !== PriceListVersion::STATUS_ACTIVE) {
            return collect($operationIds)->mapWithKeys(function ($id) {
                return [$id => $this->noPrice('version_not_active')];
            })->all();
        }

        $supplierId = $version->priceList?->supplier_id;

        $prices = OperationPrice::query()
            ->whereIn('operation_id', $operationIds)
            ->where('price_list_version_id', $priceListVersionId)
            ->where('price_type', $this->priceType)
            ->with('operation')
            ->get()
            ->keyBy('operation_id');

        return collect($operationIds)->mapWithKeys(function ($id) use ($prices, $priceListVersionId, $supplierId) {
            $price = $prices->get($id);
            if (!$price) {
                return [$id => $this->noPrice('not_found_for_version')];
            }

            return [$id => [
                'price' => (float) $price->price_per_internal_unit,
                'source' => 'project_version',
                'version_id' => $priceListVersionId,
                'supplier_id' => $supplierId,
                'unit' => $price->source_unit ?? $price->operation?->unit,
                'match_confidence' => $price->match_confidence,
            ]];
        })->all();
    }

    /**
     * Batch get prices from a specific price-list version with rule-aware thickness matching.
     *
     * For each operation, selects the best-matching price row using the supplied rule context:
     *   1. Bounded match: min_thickness <= thickness <= max_thickness, narrowest interval wins
     *   2. Lower-only match: min_thickness <= thickness, max_thickness null (most specific first)
     *   3. Upper-only match: min_thickness null, max_thickness >= thickness (narrowest first)
     *   4. Unbounded default row: both bounds null (generic fallback)
     *   5. Any row (last-resort scalar fallback, same as getPricesForVersionBatch)
     *
     * When rule_context.thickness is null, steps 1–3 are skipped.
     * Existing getPricesForVersionBatch() is untouched by this method.
     *
     * @param int $versionId
     * @param array<int, array{thickness: float|null, exclusion_group: string|null}> $ruleContexts
     *   Keyed by operation_id
     * @return array<int, array>
     */
    public function getPricesForVersionBatchWithRuleContext(
        int $versionId,
        array $ruleContexts
    ): array {
        if (empty($ruleContexts)) {
            return [];
        }

        $operationIds = array_keys($ruleContexts);

        $version = PriceListVersion::query()
            ->with('priceList')
            ->find($versionId);

        if (!$version || $version->status !== PriceListVersion::STATUS_ACTIVE) {
            return collect($operationIds)->mapWithKeys(function ($id) {
                return [$id => $this->noPrice('version_not_active')];
            })->all();
        }

        $supplierId = $version->priceList?->supplier_id;

        // Load ALL rows for these operations in this version (may be multiple per operation_id)
        $allRows = OperationPrice::query()
            ->whereIn('operation_id', $operationIds)
            ->where('price_list_version_id', $versionId)
            ->where('price_type', $this->priceType)
            ->with('operation')
            ->orderBy('id')
            ->get()
            ->groupBy('operation_id');

        return collect($operationIds)->mapWithKeys(function ($id) use ($allRows, $ruleContexts, $versionId, $supplierId) {
            $ctx      = $ruleContexts[$id] ?? [];
            $thickness = isset($ctx['thickness']) && $ctx['thickness'] !== null
                ? (float) $ctx['thickness']
                : null;
            $exclusionGroup = isset($ctx['exclusion_group']) && $ctx['exclusion_group'] !== null && $ctx['exclusion_group'] !== ''
                ? (string) $ctx['exclusion_group']
                : null;

            $rows = $allRows->get($id, collect());

            if ($rows->isEmpty()) {
                return [$id => $this->noPrice('not_found_for_version')];
            }

            $matched = $this->selectRuleAwareRow($rows, $thickness, $exclusionGroup);

            if ($matched === null) {
                return [$id => $this->noPrice('not_found_for_version')];
            }

            $isRuleMatch = $matched->min_thickness !== null || $matched->max_thickness !== null;

            return [$id => [
                'price'               => (float) $matched->price_per_internal_unit,
                'source'              => $isRuleMatch ? 'project_version_rule' : 'project_version',
                'version_id'          => $versionId,
                'supplier_id'         => $supplierId,
                'unit'                => $matched->source_unit ?? $matched->operation?->unit,
                'match_confidence'    => $matched->match_confidence,
                'matched_min_thickness' => $matched->min_thickness,
                'matched_max_thickness' => $matched->max_thickness,
            ]];
        })->all();
    }

    /**
     * Batch get prices for a list of pricing items, each carrying an arbitrary caller key.
     *
     * This variant accepts multiple items per operation_id so the caller can maintain
     * separate sub-buckets (e.g. one per thickness context). Each item is resolved
     * independently and the result is indexed by the same key the caller assigned.
     *
     * Rule-aware row selection follows the same 5-level precedence as
     * getPricesForVersionBatchWithRuleContext(). That method and its existing callers
     * are untouched by this method.
     *
     * @param int    $versionId
     * @param array<int, array{key: string, operation_id: int, thickness: float|null}> $items
     * @return array<string, array>  Keyed by item['key']
     */
    public function getPricesForVersionBatchItems(int $versionId, array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $version = PriceListVersion::query()
            ->with('priceList')
            ->find($versionId);

        if (!$version || $version->status !== PriceListVersion::STATUS_ACTIVE) {
            $result = [];
            foreach ($items as $item) {
                $result[$item['key']] = $this->noPrice('version_not_active');
            }
            return $result;
        }

        $supplierId = $version->priceList?->supplier_id;

        // One DB round-trip for all operation_ids referenced by items
        $operationIds = array_unique(array_column($items, 'operation_id'));

        $allRows = OperationPrice::query()
            ->whereIn('operation_id', $operationIds)
            ->where('price_list_version_id', $versionId)
            ->where('price_type', $this->priceType)
            ->with('operation')
            ->orderBy('id')
            ->get()
            ->groupBy('operation_id');

        $result = [];
        foreach ($items as $item) {
            $key       = $item['key'];
            $opId      = (int) $item['operation_id'];
            $thickness  = isset($item['thickness']) && $item['thickness'] !== null
                ? (float) $item['thickness']
                : null;
            $exclusionGroup = isset($item['exclusion_group']) && $item['exclusion_group'] !== null && $item['exclusion_group'] !== ''
                ? (string) $item['exclusion_group']
                : null;

            $rows = $allRows->get($opId, collect());

            if ($rows->isEmpty()) {
                $result[$key] = $this->noPrice('not_found_for_version');
                continue;
            }

            $matched = $this->selectRuleAwareRow($rows, $thickness, $exclusionGroup);

            if ($matched === null) {
                $result[$key] = $this->noPrice('not_found_for_version');
                continue;
            }

            $isRuleMatch = $matched->min_thickness !== null || $matched->max_thickness !== null;

            $result[$key] = [
                'price'                 => (float) $matched->price_per_internal_unit,
                'source'                => $isRuleMatch ? 'project_version_rule' : 'project_version',
                'version_id'            => $versionId,
                'supplier_id'           => $supplierId,
                'unit'                  => $matched->source_unit ?? $matched->operation?->unit,
                'match_confidence'      => $matched->match_confidence,
                'matched_min_thickness' => $matched->min_thickness,
                'matched_max_thickness' => $matched->max_thickness,
            ];
        }

        return $result;
    }

    /**
     * Select a single OperationPrice row using thickness-aware deterministic precedence.
     *
     * Precedence (highest priority first):
     *   1. Bounded: min_thickness <= thickness <= max_thickness, narrowest interval first
     *   2. Lower-only: min_thickness <= thickness, max_thickness null, highest lower-bound first
     *   3. Upper-only: min_thickness null, max_thickness >= thickness, lowest upper-bound first
     *   4. Unbounded: both bounds null (generic default row), first by insert order
     *   5. Any row (scalar last-resort)
     *
     * Steps 1–3 are skipped when $thickness is null.
     *
     * @param Collection<int, OperationPrice> $rows
     */
    private function selectRuleAwareRow(Collection $rows, ?float $thickness, ?string $exclusionGroup = null): ?OperationPrice
    {
        // Pre-filter by exclusion_group when provided.
        // If no rows carry this group (supplier did not tag rows), fall back to the
        // full row set so existing behavior is preserved for untagged price catalogs.
        if ($exclusionGroup !== null) {
            $groupRows = $rows->filter(fn ($r) => $r->exclusion_group === $exclusionGroup);
            if ($groupRows->isNotEmpty()) {
                $rows = $groupRows;
            }
        }

        if ($thickness !== null) {
            // Step 1: bounded match — narrowest interval wins
            $bounded = $rows->filter(fn ($r) =>
                $r->min_thickness !== null &&
                $r->max_thickness !== null &&
                (float) $r->min_thickness <= $thickness &&
                (float) $r->max_thickness >= $thickness
            )->sortBy(fn ($r) => (float) $r->max_thickness - (float) $r->min_thickness);

            if ($bounded->isNotEmpty()) {
                return $bounded->first();
            }

            // Step 2: lower-only — most specific (highest) lower bound first
            $lowerOnly = $rows->filter(fn ($r) =>
                $r->min_thickness !== null &&
                $r->max_thickness === null &&
                (float) $r->min_thickness <= $thickness
            )->sortByDesc(fn ($r) => (float) $r->min_thickness);

            if ($lowerOnly->isNotEmpty()) {
                return $lowerOnly->first();
            }

            // Step 3: upper-only — narrowest (lowest) upper bound first
            $upperOnly = $rows->filter(fn ($r) =>
                $r->min_thickness === null &&
                $r->max_thickness !== null &&
                (float) $r->max_thickness >= $thickness
            )->sortBy(fn ($r) => (float) $r->max_thickness);

            if ($upperOnly->isNotEmpty()) {
                return $upperOnly->first();
            }
        }

        // Step 4: unbounded generic row (both bounds null)
        $unbounded = $rows->filter(fn ($r) =>
            $r->min_thickness === null && $r->max_thickness === null
        );

        if ($unbounded->isNotEmpty()) {
            return $unbounded->first();
        }

        // Step 5: scalar last-resort fallback — any row (same as getPricesForVersionBatch keyBy behavior)
        return $rows->first();
    }

    /**
     * Batch get median prices.
     */
    protected function getMedianPricesBatch(array $operationIds): array
    {
        // Get base operations for unit check
        $operations = Operation::whereIn('id', $operationIds)
            ->get()
            ->keyBy('id');

        // Get all active versions
        $activeVersionIds = PriceListVersion::where('status', 'active')
            ->pluck('id');

        if ($activeVersionIds->isEmpty()) {
            return collect($operationIds)->mapWithKeys(function ($id) {
                return [$id => $this->noPrice('no_active_versions')];
            })->all();
        }

        // Get all prices for these operations from active versions
        $allPrices = OperationPrice::whereIn('operation_id', $operationIds)
            ->whereIn('price_list_version_id', $activeVersionIds)
            ->where('price_type', $this->priceType)
            ->get()
            ->groupBy('operation_id');

        return collect($operationIds)->mapWithKeys(function ($id) use ($allPrices, $operations) {
            $operation = $operations->get($id);
            if (!$operation) {
                return [$id => $this->noPrice('operation_not_found')];
            }

            $prices = $allPrices->get($id, collect());
            if ($prices->isEmpty()) {
                return [$id => $this->noPrice('no_prices')];
            }

            // Filter by matching units
            $validPrices = $prices->filter(function ($price) {
                return $price->canIncludeInMedian();
            });

            if ($validPrices->isEmpty()) {
                return [$id => $this->noPrice('no_matching_units')];
            }

            // Calculate median
            $priceValues = $validPrices->pluck('price_per_internal_unit')->sort()->values();
            $count = $priceValues->count();
            
            if ($count % 2 === 0) {
                $median = ($priceValues[$count / 2 - 1] + $priceValues[$count / 2]) / 2;
            } else {
                $median = $priceValues[floor($count / 2)];
            }

            return [$id => [
                'price' => (float) $median,
                'source' => 'median',
                'version_id' => null,
                'supplier_id' => null,
                'unit' => $operation->unit,
                'suppliers_count' => $validPrices->count(),
                'excluded_count' => $prices->count() - $validPrices->count(),
            ]];
        })->all();
    }

    /**
     * Get price comparison across all suppliers.
     */
    public function getPriceComparison(int $operationId): array
    {
        $operation = Operation::find($operationId);
        if (!$operation) {
            return [];
        }

        $activeVersions = PriceListVersion::where('status', 'active')
            ->with('priceList.supplier')
            ->get();

        $comparison = [];

        foreach ($activeVersions as $version) {
            $price = OperationPrice::where('operation_id', $operationId)
                ->where('price_list_version_id', $version->id)
                ->where('price_type', $this->priceType)
                ->first();

            if ($price) {
                $comparison[] = [
                    'supplier_id' => $version->priceList?->supplier_id,
                    'supplier_name' => $version->priceList?->supplier?->name,
                    'price' => (float) $price->price_per_internal_unit,
                    'unit' => $price->source_unit ?? $price->operation?->unit,
                    'unit_matches' => $price->hasMatchingUnits(),
                    'source_name' => $price->source_name,
                    'match_confidence' => $price->match_confidence,
                    'version_date' => $version->created_at,
                ];
            }
        }

        // Sort by price ascending
        usort($comparison, fn($a, $b) => $a['price'] <=> $b['price']);

        return [
            'operation' => [
                'id' => $operation->id,
                'name' => $operation->name,
                'unit' => $operation->unit,
            ],
            'suppliers' => $comparison,
            'median' => $this->getMedianPrice($operationId),
        ];
    }

    /**
     * Clear price cache for operation.
     */
    public function clearCache(int $operationId, ?int $supplierId = null): void
    {
        Cache::forget("op_price:median:{$operationId}:{$this->priceType}");

        if ($supplierId) {
            Cache::forget("op_price:supplier:{$supplierId}:{$operationId}:{$this->priceType}");
        }
    }

    /**
     * Clear all price caches.
     */
    public function clearAllCache(): void
    {
        // Note: This requires cache tags or pattern matching support
        // For now, we rely on TTL expiration
    }

    /**
     * Set price type (retail/wholesale).
     */
    public function setPriceType(string $type): self
    {
        $this->priceType = $type;
        return $this;
    }

    /**
     * Set default mode.
     */
    public function setDefaultMode(string $mode): self
    {
        $this->defaultMode = $mode;
        return $this;
    }

    /**
     * Return no-price result.
     */
    protected function noPrice(string $reason): array
    {
        return [
            'price' => 0.0,
            'source' => 'not_found',
            'version_id' => null,
            'supplier_id' => null,
            'unit' => null,
            'reason' => $reason,
        ];
    }

    protected function getActiveVersionForSupplier(int $supplierId): ?PriceListVersion
    {
        return PriceListVersion::query()
            ->where('status', PriceListVersion::STATUS_ACTIVE)
            ->whereHas('priceList', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            })
            ->latest('id')
            ->first();
    }
}
