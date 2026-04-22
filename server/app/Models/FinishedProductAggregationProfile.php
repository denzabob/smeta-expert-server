<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductAggregationProfile extends Model
{
    use HasFactory;

    public const METHOD_MEAN = 'mean';
    public const METHOD_MEDIAN = 'median';

    protected $fillable = [
        'finished_product_specification_id',
        'finished_product_material_id',
        'method',
        'include_only_active',
        'exclude_stale',
        'minimum_sources_count',
        'metadata',
    ];

    protected $casts = [
        'include_only_active' => 'boolean',
        'exclude_stale' => 'boolean',
        'minimum_sources_count' => 'integer',
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
}
