<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    public const STATUS_CREATED = 'created';
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAITING_FOR_CAPTURE = 'waiting_for_capture';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'uuid',
        'invoice_id',
        'user_id',
        'provider_code',
        'provider_payment_id',
        'idempotency_key',
        'amount_minor',
        'currency',
        'status',
        'confirmation_type',
        'confirmation_url',
        'confirmation_token',
        'provider_payload',
        'error_code',
        'error_message',
        'succeeded_at',
        'canceled_at',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'provider_payload' => 'array',
        'succeeded_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
