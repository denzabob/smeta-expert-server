<?php

namespace App\Models;

use App\Services\PriceImport\TextNormalizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Операция поставщика - хранит операции ровно так, как они называются у поставщика.
 * Это словарь поставщика, без попытки унифицировать.
 */
class SupplierOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'name',
        'unit',
        'category',
        'description',
        'external_key',
        'search_name',
        'origin',
    ];

    protected $casts = [
        'origin' => 'string',
    ];

    // Origin types
    public const ORIGIN_IMPORT = 'import';
    public const ORIGIN_MANUAL = 'manual';

    /**
     * Boot model events.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->search_name) && !empty($model->name)) {
                $model->search_name = TextNormalizer::normalize($model->name);
            }
            if (empty($model->external_key) && !empty($model->name)) {
                $model->external_key = md5($model->name);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('name')) {
                $model->search_name = TextNormalizer::normalize($model->name);
            }
        });
    }

    /**
     * Get the supplier.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get prices for this operation.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(SupplierOperationPrice::class);
    }

    /**
     * Get operation groups this operation belongs to.
     */
    public function operationGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            OperationGroup::class,
            'operation_group_links',
            'supplier_operation_id',
            'operation_group_id'
        )->withTimestamps();
    }

    /**
     * Get the latest price for this operation.
     */
    public function latestPrice(?string $priceType = 'retail'): ?SupplierOperationPrice
    {
        return $this->prices()
            ->where('price_type', $priceType)
            ->whereHas('priceListVersion', function ($query) {
                $query->orderByDesc('effective_date')
                      ->orderByDesc('captured_at');
            })
            ->first();
    }

    /**
     * Get price for specific version.
     */
    public function priceForVersion(int $versionId, string $priceType = 'retail'): ?SupplierOperationPrice
    {
        return $this->prices()
            ->where('price_list_version_id', $versionId)
            ->where('price_type', $priceType)
            ->first();
    }

    /**
     * Scope: filter by supplier.
     */
    public function scopeForSupplier($query, int $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Scope: search by name.
     */
    public function scopeSearch($query, string $search)
    {
        $normalized = TextNormalizer::normalize($search);
        return $query->where(function ($q) use ($search, $normalized) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('search_name', 'like', "%{$normalized}%");
        });
    }

    /**
     * Find or create supplier operation by name and supplier.
     */
    public static function findOrCreateByName(
        int $supplierId,
        string $name,
        ?string $unit = null,
        ?string $category = null,
        string $origin = self::ORIGIN_IMPORT
    ): self {
        $externalKey = md5($name);
        
        return static::firstOrCreate(
            [
                'supplier_id' => $supplierId,
                'external_key' => $externalKey,
            ],
            [
                'name' => $name,
                'unit' => $unit,
                'category' => $category,
                'origin' => $origin,
            ]
        );
    }
}
