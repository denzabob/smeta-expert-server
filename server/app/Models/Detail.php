<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Detail extends Model
{
    use HasFactory;

    protected $fillable = [
        'module_id',
        'name',
        'width_mm',
        'height_mm',
        'quantity',
        'material_id',
        'edge_type',
        'edge_config',
    ];

    protected $casts = [
        'edge_config' => 'array',
    ];

    public function module()
    {
        return $this->belongsTo(FurnitureModule::class, 'module_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function fittings()
    {
        return $this->hasMany(Fitting::class);
    }
}
