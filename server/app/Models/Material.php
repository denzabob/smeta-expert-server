<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'origin',
        'name',
        'article',
        'type',
        'price_per_unit',
        'unit',
        'source_url',
        'is_active',
        'version',
        'last_price_screenshot_path',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_per_unit' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function priceHistories()
    {
        return $this->hasMany(MaterialPriceHistory::class);
    }
}
