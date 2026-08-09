<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Enums\SourceCheckStatus;
use App\Domain\PriceIndices\Domain\SourceChecks\StatisticalSourceCheck;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalSourceCheck> */
class StatisticalSourceCheckFactory extends Factory
{
    protected $model = StatisticalSourceCheck::class;

    public function definition(): array
    {
        return [
            'source_id' => StatisticalSource::factory(),
            'started_at' => now(),
            'finished_at' => null,
            'status' => SourceCheckStatus::Running,
            'candidate_url' => null,
            'details_json' => null,
        ];
    }
}
