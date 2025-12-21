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
        'unit',
        'price_per_unit',
        'source_url',
        'last_price_screenshot_path',
        'is_active',
        'version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_per_unit' => 'decimal:2',
        'version' => 'integer',
    ];

    public function priceHistories()
    {
        return $this->hasMany(MaterialPriceHistory::class);
    }

    // Связь с пользователем (если нужно)
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
