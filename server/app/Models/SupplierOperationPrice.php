<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Цена операции поставщика по версии прайса.
 * Импорт создаёт записи только здесь, не в operations.cost_per_unit.
 */
class SupplierOperationPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_operation_id',
        'price_list_version_id',
        'price_value',
        'unit',
        'price_type',
        'currency',
        'source_row_index',
    ];

    protected $casts = [
        'price_value' => 'decimal:2',
        'source_row_index' => 'integer',
    ];

    // Price types
    public const PRICE_TYPE_RETAIL = 'retail';
    public const PRICE_TYPE_WHOLESALE = 'wholesale';

    /**
     * Get the supplier operation.
     */
    public function supplierOperation(): BelongsTo
    {
        return $this->belongsTo(SupplierOperation::class);
    }

    /**
     * Get the price list version.
     */
    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    /**
     * Get supplier through operation (denormalized access).
     */
    public function getSupplierAttribute()
    {
        return $this->supplierOperation?->supplier;
    }

    /**
     * Scope: filter by price type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('price_type', $type);
    }

    /**
     * Scope: filter by currency.
     */
    public function scopeInCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope: filter by active versions only.
     */
    public function scopeFromActiveVersions($query)
    {
        return $query->whereHas('priceListVersion', function ($q) {
            $q->where('status', PriceListVersion::STATUS_ACTIVE);
        });
    }

    /**
     * Create or update price for supplier operation.
     */
    public static function savePrice(
        int $supplierOperationId,
        int $priceListVersionId,
        float $priceValue,
        ?string $unit = null,
        string $priceType = self::PRICE_TYPE_RETAIL,
        string $currency = 'RUB',
        ?int $sourceRowIndex = null
    ): self {
        return static::updateOrCreate(
            [
                'supplier_operation_id' => $supplierOperationId,
                'price_list_version_id' => $priceListVersionId,
                'price_type' => $priceType,
            ],
            [
                'price_value' => $priceValue,
                'unit' => $unit,
                'currency' => $currency,
                'source_row_index' => $sourceRowIndex,
            ]
        );
    }
}
