<?php

namespace Database\Factories;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StatisticalImportPreview> */
final class StatisticalImportPreviewFactory extends Factory
{
    protected $model = StatisticalImportPreview::class;

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
            'status' => StatisticalImportPreviewStatus::Pending,
            'cache_key' => hash('sha256', fake()->uuid()),
            'requested_by_user_id' => null,
        ];
    }
}
