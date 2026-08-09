<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalSource> */
class StatisticalSourceFactory extends Factory
{
    protected $model = StatisticalSource::class;

    public function definition(): array
    {
        return [
            'dataset_id' => StatisticalDataset::factory(),
            'code' => 'source_'.Str::lower(Str::random(16)),
            'name' => fake()->sentence(3),
            'source_page_url' => null,
            'download_url_template' => null,
            'filename_template' => null,
            'http_method' => 'GET',
            'is_enabled' => true,
            'automatic_check_enabled' => false,
            'consecutive_failures' => 0,
            'settings_json' => null,
        ];
    }
}
