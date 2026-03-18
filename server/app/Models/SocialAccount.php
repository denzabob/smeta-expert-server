<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_username',
        'provider_email',
        'provider_phone',
        'linked_at',
        'last_used_at',
        'is_active',
        'unlinked_at',
        'raw_profile_json',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'is_active' => 'boolean',
            'unlinked_at' => 'datetime',
            'raw_profile_json' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Find social account by provider + provider_user_id.
     */
    public static function findByProvider(string $provider, string $providerUserId): ?self
    {
        return static::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->where('is_active', true)
            ->first();
    }
}
