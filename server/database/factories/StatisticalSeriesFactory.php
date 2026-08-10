<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalSeries> */
class StatisticalSeriesFactory extends Factory
{
    protected $model = StatisticalSeries::class;

    public function definition(): array
    {
        return [
            'dataset_id' => StatisticalDataset::factory(),
            'indicator_id' => fn (array $attributes): int => StatisticalIndicator::factory()->create([
                'dataset_id' => $attributes['dataset_id'],
            ])->id,
            'classifier_item_id' => fn (array $attributes): int => StatisticalClassifierItem::factory()->create([
                'dataset_id' => $attributes['dataset_id'],
            ])->id,
            'territory_id' => StatisticalTerritory::factory(),
            'frequency' => 'monthly',
            'comparison_basis' => 'previous_month',
            'unit' => 'percent',
            'metadata_json' => null,
        ];
    }
}
