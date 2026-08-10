<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportIssue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<StatisticalImportIssue> */
class StatisticalImportIssueFactory extends Factory
{
    protected $model = StatisticalImportIssue::class;

    public function definition(): array
    {
        return [
            'import_id' => StatisticalImport::factory(),
            'severity' => StatisticalImportIssueSeverity::Warning,
            'code' => 'warning_'.Str::lower(Str::random(10)),
            'message' => fake()->sentence(),
            'sheet_name' => null,
            'source_row' => null,
            'source_column' => null,
            'classifier_item_code' => null,
            'details_json' => null,
        ];
    }
}
