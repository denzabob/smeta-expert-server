<?php

namespace Tests\Feature\PriceIndices\Support;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait BuildsUserCalculationFixture
{
    /**
     * @param  array<string, string|null>  $values
     * @return array<string, mixed>
     */
    private function calculationFixture(
        array $values = ['2024-01' => '100.0000000000'],
        string $itemCode = '31.02.10.140',
        string $itemName = 'Наборы кухонной мебели',
        bool $active = true,
        string $comparisonBasis = 'previous_month',
    ): array {
        $dataset = StatisticalDataset::factory()->create([
            'code' => 'producer_price_indices_'.bin2hex(random_bytes(4)),
            'name' => 'Индексы цен производителей',
            'provider_code' => 'rosstat',
            'provider_name' => 'Росстат',
        ]);
        $sourceFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
            'original_filename' => 'producer_indices.xlsx',
            'sha256' => str_repeat('a', 64),
        ]);
        $import = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $sourceFile->id,
            'status' => $active ? StatisticalImportStatus::Published : StatisticalImportStatus::Superseded,
            'published_at' => '2026-07-01 10:00:00',
        ]);
        $indicator = StatisticalIndicator::factory()->create([
            'dataset_id' => $dataset->id,
            'code' => 'producer_price_index',
            'name' => 'Индекс цен производителей',
        ]);
        $territory = StatisticalTerritory::query()->where('code', 'RU')->first()
            ?? StatisticalTerritory::factory()->create(['code' => 'RU', 'name' => 'Российская Федерация']);
        $item = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $dataset->id,
            'item_code' => $itemCode,
            'name' => $itemName,
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($itemName),
            'metadata_json' => str_ends_with($itemCode, '.АГ') ? ['provider_code_kind' => 'rosstat_local_ag'] : null,
        ]);
        $series = StatisticalSeries::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'classifier_item_id' => $item->id,
            'territory_id' => $territory->id,
            'comparison_basis' => $comparisonBasis,
        ]);

        foreach ($values as $period => $value) {
            StatisticalObservation::factory()->create([
                'import_id' => $import->id,
                'series_id' => $series->id,
                'source_file_id' => $sourceFile->id,
                'period_start' => $period.'-01',
                'value' => $value,
                'missing_reason' => $value === null ? 'ellipsis' : null,
                'sheet_name' => '16',
                'source_row' => 20000 + count($values),
                'source_column' => 'D',
                'source_cell_address' => 'D'.(20000 + count($values)),
                'source_value_raw' => $value,
            ]);
        }

        if ($active) {
            StatisticalDatasetActiveImport::query()->create([
                'dataset_id' => $dataset->id,
                'import_id' => $import->id,
                'published_at' => '2026-07-01 10:00:00',
            ]);
        }

        return compact('dataset', 'sourceFile', 'import', 'indicator', 'territory', 'item', 'series');
    }

    private function actingAsPriceIndicesRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        Sanctum::actingAs($user);

        return $user;
    }
}
