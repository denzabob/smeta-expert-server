<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'user_id',
        'project_id',
        'metric_code',
        'feature_code',
        'quantity',
        'unit',
        'subject_type',
        'subject_id',
        'request_id',
        'idempotency_key',
        'source',
        'metadata_json',
        'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'metadata_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
