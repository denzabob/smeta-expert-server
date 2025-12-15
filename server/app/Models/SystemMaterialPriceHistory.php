<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemMaterialPriceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'system_material_id',
        'version',
        'price_per_unit',
        'source_url',
        'screenshot_path',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'price_per_unit' => 'decimal:2',
    ];

    public function material()
    {
        return $this->belongsTo(SystemMaterial::class, 'system_material_id');
    }
}


