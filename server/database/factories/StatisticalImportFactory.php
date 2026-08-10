<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalImport> */
class StatisticalImportFactory extends Factory
{
    protected $model = StatisticalImport::class;

    public function definition(): array
    {
        return [
            'dataset_id' => StatisticalDataset::factory(),
            'source_file_id' => fn (array $attributes): int => StatisticalSourceFile::factory()->create([
                'dataset_id' => $attributes['dataset_id'],
                'status' => SourceFileStatus::Active,
            ])->id,
            'importer_code' => config('price_indices.imports.importers.producer_price_indices_by_product.code'),
            'importer_version' => config('price_indices.imports.importers.producer_price_indices_by_product.version'),
            'attempt_no' => 1,
            'retry_of_import_id' => null,
            'status' => StatisticalImportStatus::Pending,
            'successful_dedupe_key' => null,
            'started_at' => null,
            'finished_at' => null,
            'ready_at' => null,
            'published_at' => null,
            'superseded_at' => null,
            'failed_at' => null,
            'rows_scanned' => 0,
            'observations_parsed' => 0,
            'observations_valid' => 0,
            'observations_rejected' => 0,
            'warnings_count' => 0,
            'errors_count' => 0,
            'initiated_by_user_id' => null,
            'published_by_user_id' => null,
            'supersedes_import_id' => null,
            'failure_code' => null,
            'failure_message' => null,
            'validation_summary_json' => null,
            'metadata_json' => null,
        ];
    }
}
