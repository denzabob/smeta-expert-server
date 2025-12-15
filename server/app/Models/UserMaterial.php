<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserMaterial extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'article',
        'type',
        'unit',
        'price_per_unit',
        'supplier',
        'source_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price_per_unit' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}


