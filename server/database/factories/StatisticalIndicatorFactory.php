<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalIndicator> */
class StatisticalIndicatorFactory extends Factory
{
    protected $model = StatisticalIndicator::class;

    public function definition(): array
    {
        return [
            'dataset_id' => StatisticalDataset::factory(),
            'code' => 'indicator_'.Str::lower(Str::random(12)),
            'name' => fake()->sentence(3),
            'description' => null,
            'data_kind' => 'index',
            'metadata_json' => null,
        ];
    }
}
