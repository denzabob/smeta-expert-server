<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalClassifierImport> */
class StatisticalClassifierImportFactory extends Factory
{
    protected $model = StatisticalClassifierImport::class;

    public function definition(): array
    {
        return [
            'classifier_id' => StatisticalClassifier::factory(),
            'source_file_id' => function (array $attributes): int {
                $classifier = StatisticalClassifier::query()->findOrFail($attributes['classifier_id']);

                return StatisticalClassifierSourceFile::factory()
                    ->for($classifier, 'classifier')
                    ->create()
                    ->id;
            },
            'attempt' => 1,
            'status' => ClassifierImportStatus::Pending,
            'parser_code' => 'classifier_test_parser',
            'parser_version' => '1.0.0',
            'started_at' => null,
            'finished_at' => null,
            'nodes_parsed' => null,
            'sections_count' => null,
            'validation_errors_count' => 0,
            'validation_warnings_count' => 0,
            'validation_summary_json' => null,
            'error_json' => null,
        ];
    }
}
