<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalClassifierVersion> */
class StatisticalClassifierVersionFactory extends Factory
{
    protected $model = StatisticalClassifierVersion::class;

    public function definition(): array
    {
        return [
            'classifier_id' => StatisticalClassifier::factory(),
            'classifier_import_id' => function (array $attributes): int {
                $classifier = StatisticalClassifier::query()->findOrFail($attributes['classifier_id']);

                return StatisticalClassifierImport::factory()
                    ->for($classifier, 'classifier')
                    ->create()
                    ->id;
            },
            'version_label' => fake()->unique()->bothify('version-####-??'),
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'approved_at' => null,
            'source_published_at' => null,
            'status' => ClassifierVersionStatus::Ready,
            'node_count' => null,
            'metadata' => null,
        ];
    }
}
