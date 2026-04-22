<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinishedProductSpecification extends Model
{
    use HasFactory;

    public const TYPE_FACADE = 'facade';

    protected $fillable = [
        'user_id',
        'product_type',
        'name',
        'article',
        'is_active',
        'facade_class',
        'base_type',
        'thickness_mm',
        'covering',
        'cover_type',
        'collection',
        'decor_label',
        'price_group_label',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'thickness_mm' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceSources(): HasMany
    {
        return $this->hasMany(FinishedProductPriceSource::class);
    }

    public function aggregationProfile(): HasOne
    {
        return $this->hasOne(FinishedProductAggregationProfile::class);
    }

    public function computedPrice(): HasOne
    {
        return $this->hasOne(FinishedProductComputedPrice::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOfType($query, string $productType)
    {
        return $query->where('product_type', $productType);
    }

    public function scopeFacades($query)
    {
        return $query->where('product_type', self::TYPE_FACADE);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
