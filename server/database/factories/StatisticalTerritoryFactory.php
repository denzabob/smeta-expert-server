<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalTerritory> */
class StatisticalTerritoryFactory extends Factory
{
    protected $model = StatisticalTerritory::class;

    public function definition(): array
    {
        $name = fake()->city();

        return [
            'code' => 'TERRITORY_'.Str::upper(Str::random(10)),
            'name' => $name,
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($name),
            'type' => 'region',
            'parent_id' => null,
            'provider_code' => null,
            'metadata_json' => null,
        ];
    }
}
