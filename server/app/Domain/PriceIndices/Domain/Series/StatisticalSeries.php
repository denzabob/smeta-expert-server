<?php

namespace App\Domain\PriceIndices\Domain\Series;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Concerns\HasPublicId;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Database\Factories\StatisticalSeriesFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatisticalSeries extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'statistical_series';

    protected $fillable = [
        'dataset_id',
        'indicator_id',
        'classifier_item_id',
        'territory_id',
        'frequency',
        'comparison_basis',
        'unit',
        'metadata_json',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $series): void {
            $indicatorDatasetId = StatisticalIndicator::query()
                ->whereKey($series->indicator_id)
                ->value('dataset_id');
            $classifierDatasetId = StatisticalClassifierItem::query()
                ->whereKey($series->classifier_item_id)
                ->value('dataset_id');

            if ((int) $indicatorDatasetId !== (int) $series->dataset_id
                || (int) $classifierDatasetId !== (int) $series->dataset_id
            ) {
                throw new PriceIndicesInvariantViolation(
                    'Series dimensions must belong to the same dataset.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(StatisticalDataset::class, 'dataset_id');
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(StatisticalIndicator::class, 'indicator_id');
    }

    public function classifierItem(): BelongsTo
    {
        return $this->belongsTo(StatisticalClassifierItem::class, 'classifier_item_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(StatisticalTerritory::class, 'territory_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(StatisticalObservation::class, 'series_id');
    }

    protected static function newFactory(): Factory
    {
        return StatisticalSeriesFactory::new();
    }
}
