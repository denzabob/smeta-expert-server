<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinishedProductPriceSource extends Model
{
    use HasFactory;

    public const KIND_PRICE_LIST_ROW = 'price_list_row';
    public const KIND_PRICE_DOCUMENT = 'price_document';
    public const KIND_URL_CAPTURE = 'url_capture';
    public const KIND_MANUAL_ENTRY = 'manual_entry';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_STALE = 'stale';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'finished_product_specification_id',
        'finished_product_material_id',
        'supplier_id',
        'source_kind',
        'price_list_version_id',
        'source_price',
        'source_unit',
        'conversion_factor_to_m2',
        'price_per_m2_normalized',
        'captured_at',
        'effective_date',
        'article',
        'category',
        'description',
        'status',
        'stale_reason',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'source_price' => 'decimal:4',
        'conversion_factor_to_m2' => 'decimal:6',
        'price_per_m2_normalized' => 'decimal:4',
        'captured_at' => 'datetime',
        'effective_date' => 'date',
        'metadata' => 'array',
    ];

    public function specification(): BelongsTo
    {
        return $this->belongsTo(FinishedProductSpecification::class, 'finished_product_specification_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'finished_product_material_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function priceListVersion(): BelongsTo
    {
        return $this->belongsTo(PriceListVersion::class);
    }

    public function evidenceAssets(): HasMany
    {
        return $this->hasMany(FinishedProductPriceEvidenceAsset::class);
    }

    public function scopeForMaterial($query, int $materialId)
    {
        return $query->where('finished_product_material_id', $materialId);
    }

    public function scopeForSpecification($query, int $specificationId)
    {
        return $query->where('finished_product_specification_id', $specificationId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeEligible($query, bool $includeOnlyActive = true, bool $excludeStale = true)
    {
        if ($includeOnlyActive) {
            $query->where('status', self::STATUS_ACTIVE);
        } else {
            $query->whereNotIn('status', [self::STATUS_INVALID, self::STATUS_SUPERSEDED]);
        }

        if ($excludeStale) {
            $query->where('status', '!=', self::STATUS_STALE);
        }

        return $query->whereNotNull('price_per_m2_normalized');
    }
}
