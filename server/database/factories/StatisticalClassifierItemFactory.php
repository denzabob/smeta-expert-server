<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalClassifierItem> */
class StatisticalClassifierItemFactory extends Factory
{
    protected $model = StatisticalClassifierItem::class;

    public function definition(): array
    {
        $name = fake()->words(4, true);

        return [
            'dataset_id' => StatisticalDataset::factory(),
            'classifier_code' => 'okpd2_based',
            'item_code' => sprintf('31.%02d.%02d.%03d', fake()->numberBetween(1, 99), fake()->numberBetween(1, 99), fake()->numberBetween(1, 999)),
            'name' => $name,
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($name),
            'parent_item_id' => null,
            'valid_from' => null,
            'valid_to' => null,
            'metadata_json' => null,
        ];
    }
}
