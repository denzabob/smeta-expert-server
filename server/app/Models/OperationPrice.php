<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OperationPrice - цена операции в версии прайс-листа поставщика.
 * 
 * ВАЖНО: Это основной источник цен для расчёта сметы.
 * operations.cost_per_unit НЕ используется в расчётах (legacy).
 * 
 * Архитектура snapshot-prices:
 * - Каждая цена привязана к supplier_id + price_list_version_id
 * - Поддерживает разные типы цен (retail/wholesale)
 * - Хранит данные для аудита (source_name, external_key)
 * - Отслеживает уверенность сопоставления (match_confidence)
 */
class OperationPrice extends Model
{
    use HasFactory;

    // Price types
    public const PRICE_TYPE_RETAIL = 'retail';
    public const PRICE_TYPE_WHOLESALE = 'wholesale';

    // Match confidence levels
    public const MATCH_ALIAS = 'alias';
    public const MATCH_EXACT = 'exact';
    public const MATCH_FUZZY = 'fuzzy';
    public const MATCH_MANUAL = 'manual';

    protected $fillable = [
        'supplier_id',
        'price_list_version_id',
        'operation_id',
        'source_price',
        'source_unit',
        'conversion_factor',
        'price_per_internal_unit',
        'currency',
        'source_row_index',
        'price_type',
        'source_name',
        'external_key',
        'match_confidence',
        'meta',
        'category',
        'description',
        'min_thickness',
        'max_thickness',
        'exclusion_group',
    ];

    protected $casts = [
        'source_price' => 'decimal:4',
        'conversion_factor' => 'decimal:6',
        'price_per_internal_unit' => 'decimal:4',
        'min_thickness' => 'decimal:2',
        'max_thickness' => 'decimal:2',
        'source_row_index' => 'integer',
        'meta' => 'array',
    ];

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the price list version.
     */
    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    /**
     * Get the operation.
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /**
     * Scope to active price list versions only.
     */
    public function scopeFromActiveVersions($query)
    {
        return $query->whereHas('priceListVersion', function ($q) {
            $q->where('status', PriceListVersion::STATUS_ACTIVE);
        });
    }

    /**
     * Scope to specific supplier.
     */
    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope to specific price type.
     */
    public function scopeOfType($query, string $priceType)
    {
        return $query->where('price_type', $priceType);
    }

    /**
     * Check if units match (no conversion needed).
     */
    public function hasMatchingUnits(): bool
    {
        if (!$this->operation) {
            return true;
        }
        
        // If source_unit is NULL, assume it matches
        if ($this->source_unit === null) {
            return true;
        }
        
        $sourceUnit = self::normalizeUnit($this->source_unit);
        $operationUnit = self::normalizeUnit($this->operation->unit);

        return $sourceUnit === $operationUnit
            || $this->conversion_factor != 1.0;
    }

    /**
     * Check if this price can be included in median calculation.
     * Unit mismatch with conversion_factor=1 is excluded.
     */
    public function canIncludeInMedian(): bool
    {
        if (!$this->operation) {
            return false;
        }
        
        // If source_unit is NULL, assume it matches the operation unit
        if ($this->source_unit === null) {
            return true;
        }
        
        // If units match - OK
        $sourceUnit = self::normalizeUnit($this->source_unit);
        $operationUnit = self::normalizeUnit($this->operation->unit);

        if ($sourceUnit === $operationUnit) {
            return true;
        }
        
        // If units don't match but conversion_factor is set - OK
        if ($this->conversion_factor != 1.0) {
            return true;
        }
        
        // Unit mismatch with conversion_factor=1 - exclude from median
        return false;
    }

    /**
     * Canonicalize units to avoid false mismatches (e.g. "м2" vs "м²").
     */
    public static function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $raw = mb_strtolower(trim($unit), 'UTF-8');
        if ($raw === '') {
            return null;
        }

        $compact = str_replace([' ', "\t", "\n", "\r", '.', ',', '·'], '', $raw);

        $map = [
            'м2' => 'м²',
            'm2' => 'м²',
            'м^2' => 'м²',
            'м²' => 'м²',
            'квм' => 'м²',
            'квметр' => 'м²',
            'мп' => 'м.п.',
            'пм' => 'м.п.',
            'погм' => 'м.п.',
            'мпог' => 'м.п.',
            'шт' => 'шт.',
            'рез' => 'рез',
            'деталь' => 'деталь',
            'дет' => 'деталь',
        ];

        return $map[$compact] ?? $raw;
    }

    /**
     * Calculate and set internal price.
     */
    public function calculateInternalPrice(): void
    {
        if ($this->conversion_factor == 0) {
            throw new \InvalidArgumentException('Conversion factor cannot be zero');
        }
        
        $this->price_per_internal_unit = $this->source_price / $this->conversion_factor;
    }

    /**
     * Boot method.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // Auto-calculate internal price if not set
            if ($model->source_price && $model->conversion_factor && !$model->price_per_internal_unit) {
                $model->calculateInternalPrice();
            }
        });
    }
}
