<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierProductAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'external_key',
        'external_name',
        'internal_item_type',
        'internal_item_id',
        'supplier_unit',
        'internal_unit',
        'conversion_factor',
        'price_transform',
        'confidence',
        'similarity_score',
        'first_seen_at',
        'last_seen_at',
        'usage_count',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'similarity_score' => 'decimal:4',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'usage_count' => 'integer',
    ];

    // Internal item types
    public const TYPE_MATERIAL = 'material';
    public const TYPE_OPERATION = 'operation';

    // Confidence levels
    public const CONFIDENCE_MANUAL = 'manual';
    public const CONFIDENCE_AUTO_EXACT = 'auto_exact';
    public const CONFIDENCE_AUTO_FUZZY = 'auto_fuzzy';

    // Price transform rules
    public const TRANSFORM_DIVIDE = 'divide';
    public const TRANSFORM_MULTIPLY = 'multiply';
    public const TRANSFORM_NONE = 'none';

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the internal item (polymorphic).
     */
    public function internalItem()
    {
        return $this->morphTo('internal_item', 'internal_item_type', 'internal_item_id');
    }

    /**
     * Get material if type is material.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'internal_item_id');
    }

    /**
     * Get operation if type is operation.
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'internal_item_id');
    }

    /**
     * Calculate internal price from supplier price.
     */
    public function calculateInternalPrice(float $supplierPrice): float
    {
        if ($this->conversion_factor == 0) {
            throw new \InvalidArgumentException('Conversion factor cannot be zero');
        }

        return match ($this->price_transform) {
            self::TRANSFORM_DIVIDE => $supplierPrice / $this->conversion_factor,
            self::TRANSFORM_MULTIPLY => $supplierPrice * $this->conversion_factor,
            self::TRANSFORM_NONE => $supplierPrice,
            default => $supplierPrice / $this->conversion_factor,
        };
    }

    /**
     * Update usage tracking.
     */
    public function recordUsage(): void
    {
        $now = now();
        
        if (!$this->first_seen_at) {
            $this->first_seen_at = $now;
        }
        
        $this->last_seen_at = $now;
        $this->usage_count++;
        $this->save();
    }

    /**
     * Find alias by external key.
     */
    public static function findByExternalKey(int $supplierId, string $externalKey, string $itemType): ?self
    {
        return self::where('supplier_id', $supplierId)
            ->where('external_key', $externalKey)
            ->where('internal_item_type', $itemType)
            ->first();
    }

    /**
     * Generate stable external key from name.
     */
    public static function generateExternalKey(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        return md5($normalized);
    }

    /**
     * Scope for supplier.
     */
    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope for item type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('internal_item_type', $type);
    }

    /**
     * Scope for operations.
     */
    public function scopeOperations($query)
    {
        return $query->where('internal_item_type', self::TYPE_OPERATION);
    }

    /**
     * Scope for materials.
     */
    public function scopeMaterials($query)
    {
        return $query->where('internal_item_type', self::TYPE_MATERIAL);
    }
}
