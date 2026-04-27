<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPaymentMethod extends Model
{
    protected $fillable = [
        'user_id',
        'provider_code',
        'provider_payment_method_id',
        'status',
        'title',
        'card_last4',
        'card_type',
        'expires_at',
        'provider_payload',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'provider_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
