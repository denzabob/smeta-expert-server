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
