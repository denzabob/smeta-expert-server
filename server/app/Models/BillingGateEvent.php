<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingGateEvent extends Model
{
    protected $fillable = [
        'user_id',
        'plan_code',
        'capability',
        'limit_value',
        'usage_value',
        'would_block',
        'enforced',
        'context_json',
    ];

    protected $casts = [
        'limit_value' => 'integer',
        'usage_value' => 'integer',
        'would_block' => 'boolean',
        'enforced' => 'boolean',
        'context_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
