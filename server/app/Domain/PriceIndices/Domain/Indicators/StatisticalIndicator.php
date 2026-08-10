<?php

namespace App\Domain\PriceIndices\Domain\Indicators;

use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Database\Factories\StatisticalIndicatorFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalIndicator extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'dataset_id',
        'code',
        'name',
        'description',
        'data_kind',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(StatisticalSeries::class, 'indicator_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalIndicatorFactory::new();
    }
}
