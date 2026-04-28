<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'admin_user_id',
        'subscription_id',
        'event_type',
        'old_plan_code',
        'new_plan_code',
        'old_status',
        'new_status',
        'old_period_end',
        'new_period_end',
        'reason',
        'context_json',
    ];

    protected $casts = [
        'old_period_end' => 'datetime',
        'new_period_end' => 'datetime',
        'context_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class);
    }
}
