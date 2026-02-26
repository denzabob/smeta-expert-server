<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Группа операций - мост между операциями поставщиков и сметой.
 * Например: "Распил", "Кромкооблицовка", "Сверление"
 */
class OperationGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'note',
        'expected_unit',
    ];

    /**
     * Get the user who owns this group.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get supplier operations in this group.
     */
    public function supplierOperations(): BelongsToMany
    {
        return $this->belongsToMany(
            SupplierOperation::class,
            'operation_group_links',
            'operation_group_id',
            'supplier_operation_id'
        )->withTimestamps();
    }

    /**
     * Get links to supplier operations.
     */
    public function links(): HasMany
    {
        return $this->hasMany(OperationGroupLink::class);
    }

    /**
     * Get median price for this group.
     * Returns null if units don't match or no prices available.
     * 
     * @param string $priceType 'retail' | 'wholesale'
     * @param string $currency Currency code
     * @return array|null ['value' => float, 'count' => int, 'unit' => string] or null with error
     */
    public function getMedianPrice(
        string $priceType = 'retail',
        string $currency = 'RUB'
    ): ?array {
        $prices = $this->collectLatestPrices($priceType, $currency);
        
        if ($prices->isEmpty()) {
            return [
                'error' => 'no_prices',
                'message' => 'Нет доступных цен для расчета медианы',
            ];
        }

        // Check unit consistency
        $units = $prices->pluck('unit')->unique()->filter();
        if ($units->count() > 1) {
            return [
                'error' => 'unit_mismatch',
                'message' => 'Несоответствие единиц измерения',
                'units' => $units->values()->toArray(),
                'prices' => $prices->toArray(),
            ];
        }

        // Calculate median
        $values = $prices->pluck('price_value')->sort()->values();
        $count = $values->count();
        
        if ($count === 0) {
            return [
                'error' => 'no_prices',
                'message' => 'Нет доступных цен для расчета медианы',
            ];
        }

        $median = $count % 2 === 0
            ? ($values[$count / 2 - 1] + $values[$count / 2]) / 2
            : $values[floor($count / 2)];

        return [
            'value' => round($median, 2),
            'count' => $count,
            'unit' => $units->first() ?? $this->expected_unit,
            'currency' => $currency,
            'price_type' => $priceType,
            'min' => $values->min(),
            'max' => $values->max(),
            'prices' => $prices->toArray(),
        ];
    }

    /**
     * Collect latest prices from all linked supplier operations.
     */
    public function collectLatestPrices(
        string $priceType = 'retail',
        string $currency = 'RUB'
    ): Collection {
        $prices = collect();

        foreach ($this->supplierOperations()->with('supplier')->get() as $supplierOp) {
            // Get latest price for this supplier operation
            $latestPrice = SupplierOperationPrice::query()
                ->where('supplier_operation_id', $supplierOp->id)
                ->where('price_type', $priceType)
                ->where('currency', $currency)
                ->whereHas('priceListVersion', function ($query) {
                    $query->where('status', PriceListVersion::STATUS_ACTIVE);
                })
                ->orderByDesc(
                    PriceListVersion::select('effective_date')
                        ->whereColumn('price_list_versions.id', 'supplier_operation_prices.price_list_version_id')
                        ->limit(1)
                )
                ->first();

            if ($latestPrice) {
                $prices->push([
                    'supplier_operation_id' => $supplierOp->id,
                    'supplier_id' => $supplierOp->supplier_id,
                    'supplier_name' => $supplierOp->supplier->name ?? 'Unknown',
                    'operation_name' => $supplierOp->name,
                    'price_value' => (float) $latestPrice->price_value,
                    'unit' => $latestPrice->unit ?? $supplierOp->unit,
                    'price_list_version_id' => $latestPrice->price_list_version_id,
                ]);
            }
        }

        return $prices;
    }

    /**
     * Check if all linked operations have consistent units.
     */
    public function hasConsistentUnits(): bool
    {
        $units = $this->supplierOperations()
            ->whereNotNull('unit')
            ->distinct()
            ->pluck('unit');

        return $units->count() <= 1;
    }

    /**
     * Get unique units from linked operations.
     */
    public function getUniqueUnits(): Collection
    {
        return $this->supplierOperations()
            ->whereNotNull('unit')
            ->distinct()
            ->pluck('unit');
    }

    /**
     * Scope: filter by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
