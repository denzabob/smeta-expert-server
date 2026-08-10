<?php

namespace App\Domain\PriceIndices\Domain\Territories;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Database\Factories\StatisticalTerritoryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalTerritory extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'type',
        'parent_id',
        'provider_code',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(StatisticalSeries::class, 'territory_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalTerritoryFactory::new();
    }
}
