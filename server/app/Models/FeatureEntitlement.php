<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeatureEntitlement extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'feature_code',
        'enabled',
        'source',
        'starts_at',
        'ends_at',
        'metadata_json',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function scopeActiveForDate(Builder $query, mixed $date): Builder
    {
        return $query
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $date);
            });
    }
}
