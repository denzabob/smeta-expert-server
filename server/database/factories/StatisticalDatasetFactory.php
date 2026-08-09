<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalDataset> */
class StatisticalDatasetFactory extends Factory
{
    protected $model = StatisticalDataset::class;

    public function definition(): array
    {
        return [
            'code' => 'dataset_'.Str::lower(Str::random(16)),
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'provider_code' => 'provider_'.Str::lower(Str::random(8)),
            'provider_name' => fake()->company(),
            'data_kind' => 'price_index',
            'frequency' => 'monthly',
            'classifier_code' => 'okpd2_based',
            'territory_scope' => 'russian_federation',
            'is_enabled' => true,
            'automatic_check_enabled' => false,
            'check_schedule' => null,
            'metadata_json' => null,
        ];
    }
}
