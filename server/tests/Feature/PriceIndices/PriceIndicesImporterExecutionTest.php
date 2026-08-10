<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\CreateStatisticalImport;
use App\Domain\PriceIndices\Application\Services\StartStatisticalImport;
use App\Domain\PriceIndices\Application\Services\StatisticalImporterRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\PriceIndices\Support\BuildsStatisticalImportWorkbook;
use Tests\TestCase;

class PriceIndicesImporterExecutionTest extends TestCase
{
    use BuildsStatisticalImportWorkbook;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake($this->importTestDisk);
        Config::set('price_indices.imports.chunk_rows', 1);
        Config::set('price_indices.imports.db_batch_size', 2);
    }

    public function test_import_batches_observations_reuses_entities_and_updates_counters(): void
    {
        $dataset = $this->createReferenceDataset();
        StatisticalClassifierItem::factory()->create([
            'dataset_id' => $dataset->id,
            'classifier_code' => 'okpd2_based',
            'item_code' => '05.10.10.101',
            'name' => 'Сохранённое имя',
            'normalized_name' => 'сохранённое имя',
        ]);
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $import = app(CreateStatisticalImport::class)->execute($dataset, $file);
        $import = app(StartStatisticalImport::class)->execute($import);

        $result = app(StatisticalImporterRegistry::class)->forImport($import)->import($import);
        $import->refresh();

        $this->assertSame(14, $result->observationsCreated);
        $this->assertSame(3, $result->uniqueItems);
        $this->assertSame(14, $import->observations_valid);
        $this->assertSame(14, $import->observations_parsed);
        $this->assertSame(0, $import->errors_count);
        $this->assertSame(StatisticalImportStatus::Importing, $import->status);
        $this->assertSame(3, StatisticalClassifierItem::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(3, StatisticalSeries::query()->where('dataset_id', $dataset->id)->count());
        $this->assertDatabaseHas('statistical_classifier_items', [
            'dataset_id' => $dataset->id,
            'classifier_code' => 'okpd2_based',
            'item_code' => '05.10.10.101',
        ]);
        $localItem = StatisticalClassifierItem::query()
            ->where('dataset_id', $dataset->id)
            ->where('classifier_code', 'okpd2_based')
            ->where('item_code', '05.10.10.101.АГ')
            ->sole();
        $this->assertSame('rosstat_local_ag', $localItem->metadata_json['provider_code_kind'] ?? null);
        $this->assertDatabaseHas('statistical_import_issues', [
            'import_id' => $import->id,
            'code' => 'classifier_name_changed',
        ]);
        $this->assertDatabaseHas('statistical_observations', [
            'import_id' => $import->id,
            'period_start' => '2026-02-01',
            'value' => '99.9900000000',
            'sheet_name' => 'regional-2026',
            'source_row' => 6,
            'source_column' => 'D',
            'source_cell_address' => 'D6',
        ]);
        $this->assertDatabaseHas('statistical_observations', [
            'import_id' => $import->id,
            'missing_reason' => 'ellipsis',
        ]);
        $this->assertDatabaseHas('statistical_observations', [
            'import_id' => $import->id,
            'footnote_marker' => '1)',
            'source_value_raw' => '97,511)',
        ]);
    }

    public function test_fatal_parse_cleans_partial_observations_but_keeps_import_and_issues(): void
    {
        Config::set('price_indices.imports.db_batch_size', 1);
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeFormulaWorkbook());
        $import = app(CreateStatisticalImport::class)->execute($dataset, $file);
        $import = app(StartStatisticalImport::class)->execute($import);

        try {
            app(StatisticalImporterRegistry::class)->forImport($import)->import($import);
            $this->fail('Formula workbook unexpectedly imported.');
        } catch (StatisticalImportParsingFailed $exception) {
            $this->assertSame('workbook_validation_failed', $exception->failureCode);
        }

        $this->assertTrue($import->refresh()->exists);
        $this->assertSame(0, StatisticalObservation::query()->where('import_id', $import->id)->count());
        $this->assertDatabaseHas('statistical_import_issues', [
            'import_id' => $import->id,
            'severity' => 'fatal',
            'code' => 'formula_in_supported_cell',
        ]);
        $this->assertGreaterThan(0, StatisticalClassifierItem::query()->where('dataset_id', $dataset->id)->count());
    }
}
