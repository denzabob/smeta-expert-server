<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalObservation> */
class StatisticalObservationFactory extends Factory
{
    protected $model = StatisticalObservation::class;

    public function definition(): array
    {
        return [
            'import_id' => StatisticalImport::factory(),
            'series_id' => fn (array $attributes): int => StatisticalSeries::factory()->create([
                'dataset_id' => StatisticalImport::query()->findOrFail($attributes['import_id'])->dataset_id,
            ])->id,
            'period_start' => '2026-06-01',
            'value' => '99.9900000000',
            'missing_reason' => null,
            'source_file_id' => fn (array $attributes): int => StatisticalImport::query()
                ->findOrFail($attributes['import_id'])
                ->source_file_id,
            'sheet_name' => '24',
            'source_row' => 20920,
            'source_column' => 'H',
            'source_cell_address' => 'H20920',
            'source_value_raw' => '99.99',
            'footnote_marker' => null,
            'metadata_json' => null,
        ];
    }
}
