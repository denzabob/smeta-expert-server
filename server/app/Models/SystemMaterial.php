<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'article',
        'type',
        'unit',
        'price_per_unit',
        'supplier',
        'source_url',
        'screenshot_path',
        'is_active',
        'version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_per_unit' => 'decimal:2',
    ];

    public function priceHistories()
    {
        return $this->hasMany(SystemMaterialPriceHistory::class);
    }
}


