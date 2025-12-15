<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fitting extends Model
{
    use HasFactory;

    protected $fillable = [
        'detail_id',
        'name',
        'article',
        'type',
        'quantity',
        'unit_price',
        'source_url',
    ];

    public function detail()
    {
        return $this->belongsTo(Detail::class);
    }
}
