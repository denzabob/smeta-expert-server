<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'type',
        'description',
        'default_currency',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public const TYPE_OPERATIONS = 'operations';
    public const TYPE_MATERIALS = 'materials';
    public const DOMAIN_OPERATIONS = 'operations';
    public const DOMAIN_MATERIALS = 'materials';
    public const DOMAIN_FINISHED_PRODUCTS = 'finished_products';

    /**
     * Get the supplier that owns this price list.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get all versions of this price list.
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PriceListVersion::class)->orderBy('version_number', 'desc');
    }

    /**
     * Get the latest version.
     */
    public function latestVersion()
    {
        return $this->hasOne(PriceListVersion::class)->latestOfMany('version_number');
    }

    /**
     * Get the active version.
     */
    public function activeVersion()
    {
        return $this->hasOne(PriceListVersion::class)
            ->where('status', PriceListVersion::STATUS_ACTIVE)
            ->orderByDesc('version_number');
    }

    /**
     * Get the next version number.
     */
    public function getNextVersionNumber(): int
    {
        return ($this->versions()->max('version_number') ?? 0) + 1;
    }

    /**
     * Scope to operations type.
     */
    public function scopeOperations($query)
    {
        return $query->where('type', self::TYPE_OPERATIONS);
    }

    /**
     * Scope to materials type.
     */
    public function scopeMaterials($query)
    {
        return $query->where('type', self::TYPE_MATERIALS);
    }

    /**
     * Scope materials that belong to raw materials domain (legacy-safe).
     */
    public function scopeRawMaterialsDomain($query)
    {
        return $query->where('type', self::TYPE_MATERIALS)
            ->where(function ($q) {
                $q->whereNull('metadata->domain')
                    ->orWhere('metadata->domain', self::DOMAIN_MATERIALS);
            })
            ->where(function ($q) {
                $q->whereNull('metadata->purpose')
                    ->orWhere('metadata->purpose', '!=', 'facades');
            });
    }

    /**
     * Scope materials that belong to finished products domain.
     * Supports both new `metadata.domain` and legacy `metadata.purpose=facades`.
     */
    public function scopeFinishedProductsDomain($query)
    {
        return $query->where('type', self::TYPE_MATERIALS)
            ->where(function ($q) {
                $q->where('metadata->domain', self::DOMAIN_FINISHED_PRODUCTS)
                    ->orWhere('metadata->purpose', 'facades');
            });
    }

    /**
     * Scope by business domain.
     */
    public function scopeForDomain($query, ?string $domain)
    {
        if (!$domain) {
            return $query;
        }

        return match ($domain) {
            self::DOMAIN_OPERATIONS => $query->where('type', self::TYPE_OPERATIONS),
            self::DOMAIN_MATERIALS => $query->rawMaterialsDomain(),
            self::DOMAIN_FINISHED_PRODUCTS => $query->finishedProductsDomain(),
            default => $query,
        };
    }

    /**
     * Scope to active price lists.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Resolve domain for API/UI.
     */
    public function resolveDomain(): string
    {
        if ($this->type === self::TYPE_OPERATIONS) {
            return self::DOMAIN_OPERATIONS;
        }

        $domain = data_get($this->metadata, 'domain');
        if ($domain === self::DOMAIN_FINISHED_PRODUCTS || data_get($this->metadata, 'purpose') === 'facades') {
            return self::DOMAIN_FINISHED_PRODUCTS;
        }

        return self::DOMAIN_MATERIALS;
    }

    /**
     * Normalize metadata by type/domain to keep backward compatibility.
     */
    public static function normalizeMetadataForType(string $type, ?array $metadata): array
    {
        $meta = $metadata ?? [];

        if ($type === self::TYPE_OPERATIONS) {
            unset($meta['domain']);
            return $meta;
        }

        if (!isset($meta['domain'])) {
            $meta['domain'] = (($meta['purpose'] ?? null) === 'facades')
                ? self::DOMAIN_FINISHED_PRODUCTS
                : self::DOMAIN_MATERIALS;
        }

        if ($meta['domain'] === self::DOMAIN_FINISHED_PRODUCTS && !isset($meta['purpose'])) {
            // Keep legacy-compatible hint for existing DMS/filters.
            $meta['purpose'] = 'facades';
        }

        return $meta;
    }
}
