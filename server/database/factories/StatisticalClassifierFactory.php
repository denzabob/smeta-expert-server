<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalClassifier> */
class StatisticalClassifierFactory extends Factory
{
    protected $model = StatisticalClassifier::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('classifier_??????'),
            'standard_code' => fake()->bothify('STANDARD-####'),
            'name' => fake()->words(3, true),
            'issuing_authority' => fake()->company(),
            'responsible_body' => null,
            'official_distributor' => null,
        ];
    }
}
