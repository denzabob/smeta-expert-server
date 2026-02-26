<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'price_list_version_id',
        'material_id',
        'supplier_id',
        'source_price',
        'source_unit',
        'conversion_factor',
        'price_per_internal_unit',
        'currency',
        'price_type',
        'source_row_index',
        'article',
        'category',
        'description',
        'thickness',
    ];

    protected $casts = [
        'source_price' => 'decimal:4',
        'conversion_factor' => 'decimal:6',
        'price_per_internal_unit' => 'decimal:4',
        'thickness' => 'decimal:2',
        'source_row_index' => 'integer',
    ];

    /**
     * Get the price list version.
     */
    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    /**
     * Get the material.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
