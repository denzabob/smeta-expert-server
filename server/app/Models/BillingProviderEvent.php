<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingProviderEvent extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_IGNORED = 'ignored';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider_code',
        'event_type',
        'provider_object_id',
        'provider_payment_id',
        'payload',
        'headers',
        'processing_status',
        'processing_error',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'processed_at' => 'datetime',
    ];
}
