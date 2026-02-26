<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceListVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'price_list_id',
        'version_number',
        'sha256',
        'size_bytes',
        'currency',
        'effective_date',
        'captured_at',
        'file_path',
        'storage_disk',
        'original_filename',
        'source_type',
        'source_url',
        'manual_label',
        'status',
        'metadata',
        'notes',
    ];

    protected $casts = [
        'metadata' => 'array',
        'effective_date' => 'date',
        'captured_at' => 'datetime',
        'version_number' => 'integer',
        'size_bytes' => 'integer',
    ];

    // Новые статусы согласно ТЗ
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    
    // Типы источников
    public const SOURCE_FILE = 'file';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_URL = 'url';

    /**
     * Get the price list that owns this version.
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * Get operation prices for this version.
     */
    public function operationPrices(): HasMany
    {
        return $this->hasMany(OperationPrice::class);
    }

    /**
     * Get material prices for this version.
     */
    public function materialPrices(): HasMany
    {
        return $this->hasMany(MaterialPrice::class);
    }

    /**
     * Get import sessions for this version.
     */
    public function importSessions(): HasMany
    {
        return $this->hasMany(PriceImportSession::class);
    }

    /**
     * Get the full storage path.
     */
    public function getStoragePath(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        return storage_path("app/{$this->file_path}");
    }

    /**
     * Check if this version can be activated.
     */
    public function canActivate(): bool
    {
        return in_array($this->status, [self::STATUS_INACTIVE, self::STATUS_ARCHIVED]);
    }

    /**
     * Activate this version and archive previous active version.
     * ТРАНЗАКЦИОННО согласно ТЗ.
     */
    public function activate(): bool
    {
        if (!$this->canActivate()) {
            return false;
        }

        return \DB::transaction(function () {
            // Перевести текущую active → archived
            self::where('price_list_id', $this->price_list_id)
                ->where('status', self::STATUS_ACTIVE)
                ->update(['status' => self::STATUS_ARCHIVED]);

            // Активировать эту версию
            $this->status = self::STATUS_ACTIVE;
            return $this->save();
        });
    }

    /**
     * Archive this version (only if not active).
     */
    public function archive(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Cannot archive active version. Activate another version first.');
        }

        $this->status = self::STATUS_ARCHIVED;
        return $this->save();
    }

    /**
     * Scope to active versions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to inactive versions.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', self::STATUS_INACTIVE);
    }

    /**
     * Scope to archived versions.
     */
    public function scopeArchived($query)
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    /**
     * Scope to non-archived versions (active or inactive).
     */
    public function scopeNotArchived($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_INACTIVE]);
    }
}
