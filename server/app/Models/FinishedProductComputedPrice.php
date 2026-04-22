<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinishedProductComputedPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'finished_product_specification_id',
        'finished_product_material_id',
        'computed_price_per_m2',
        'method',
        'source_count',
        'min_price',
        'max_price',
        'computed_at',
        'metadata',
    ];

    protected $casts = [
        'computed_price_per_m2' => 'decimal:4',
        'source_count' => 'integer',
        'min_price' => 'decimal:4',
        'max_price' => 'decimal:4',
        'computed_at' => 'datetime',
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
