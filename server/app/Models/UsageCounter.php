<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'metric_code',
        'period_start',
        'period_end',
        'quantity',
        'limit_snapshot',
    ];

    protected $casts = [
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'quantity' => 'decimal:4',
        'limit_snapshot' => 'array',
    ];
}
