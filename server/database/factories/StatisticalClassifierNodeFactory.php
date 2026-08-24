<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalClassifierNode> */
class StatisticalClassifierNodeFactory extends Factory
{
    protected $model = StatisticalClassifierNode::class;

    public function definition(): array
    {
        $name = fake()->words(4, true);

        return [
            'classifier_version_id' => StatisticalClassifierVersion::factory(),
            'code' => fake()->unique()->bothify('##.##.##.###'),
            'name' => $name,
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($name),
            'semantic_level' => ClassifierSemanticLevel::Section,
            'formal_depth' => 1,
            'parent_node_id' => null,
            'source_order' => null,
            'notes_text' => null,
            'metadata_json' => null,
        ];
    }
}
