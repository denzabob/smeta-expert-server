<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'features_json',
        'limits_json',
        'metadata_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'features_json' => 'array',
        'limits_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class, 'plan_id');
    }
}
