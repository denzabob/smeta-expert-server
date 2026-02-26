<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialPriceHistory extends Model
{
    use HasFactory;

    // Source types
    public const SOURCE_WEB = 'web';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_PRICE_LIST = 'price_list';
    public const SOURCE_CHROME_EXT = 'chrome_ext';

    protected $fillable = [
        'material_id',
        'version',
        'valid_from',
        'valid_to',
        'price_per_unit',
        'source_url',
        'screenshot_path',
        // New observation fields
        'region_id',
        'observed_at',
        'source_type',
        'parse_session_id',
        'snapshot_path',
        'is_verified',
        'currency',
        'availability',
    ];

    protected $casts = [
        'price_per_unit' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'observed_at' => 'datetime',
        'is_verified' => 'boolean',
    ];

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function parseSession()
    {
        return $this->belongsTo(ParsingSession::class, 'parse_session_id');
    }

    // --- Scopes ---

    /**
     * Scope: observations for a specific region (with fallback to any).
     */
    public function scopeForRegion($query, $regionId)
    {
        if ($regionId) {
            return $query->where(function ($q) use ($regionId) {
                $q->where('region_id', $regionId)
                  ->orWhereNull('region_id');
            });
        }
        return $query;
    }

    /**
     * Scope: verified observations only.
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope: recent (within N days).
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('observed_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: latest observation first.
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('observed_at');
    }
}
