<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'article',
        'type',
        'price_per_unit',
        'unit',
        'source_url',
        'is_active',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
