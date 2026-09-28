<?php

namespace App\Models;

use App\Services\Storage\ObjectStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialPriceHistory extends Model
{
    use \App\Models\Concerns\AccountsStorageReferences;

    protected $hidden = ['screenshot_path', 'snapshot_path', 'storage_disk'];
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
        'storage_disk',
        // New observation fields
        'region_id',
        'observed_at',
        'source_type',
        'parse_session_id',
        'snapshot_path',
        'is_verified',
        'true_score',
        'currency',
        'availability',
        'raw_source_url',
        'normalized_source_url',
        'evidence_artifact_id',
        'evidence_mode',
        'is_auto_verified',
        'validation_confidence',
        'evidence_record_id',
    ];

    protected $casts = [
        'price_per_unit' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'observed_at' => 'datetime',
        'is_verified' => 'boolean',
        'is_auto_verified' => 'boolean',
        'true_score' => 'integer',
        'validation_confidence' => 'integer',
    ];

    /**
     * Smeta screenshot/snapshot locators are S1-backed. Keep the business row
     * self-describing even when an older write flow omitted storage_disk.
     */
    protected static function booted(): void
    {
        static::saving(function (self $history): void {
            foreach ([$history->screenshot_path, $history->snapshot_path] as $path) {
                if (is_string($path) && str_starts_with($path, 'smeta/')) {
                    if ($history->storage_disk !== ObjectStorage::DISK) {
                        $history->storage_disk = ObjectStorage::DISK;
                    }
                    return;
                }
            }
        });
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function parseSession(): BelongsTo
    {
        return $this->belongsTo(ParsingSession::class, 'parse_session_id');
    }

    public function evidenceArtifact(): BelongsTo
    {
        return $this->belongsTo(EvidenceArtifact::class, 'evidence_artifact_id');
    }

    public function evidenceRecord(): BelongsTo
    {
        return $this->belongsTo(EvidenceRecord::class, 'evidence_record_id');
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
