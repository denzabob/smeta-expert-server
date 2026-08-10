<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\StatisticalImporterRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportIssue;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\PriceIndices\Support\BuildsStatisticalImportWorkbook;
use Tests\TestCase;

class PriceIndicesImportPreviewTest extends TestCase
{
    use BuildsStatisticalImportWorkbook;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake($this->importTestDisk);
        Config::set('price_indices.imports.chunk_rows', 1);
    }

    public function test_preview_uses_shared_grammar_and_writes_no_import_data(): void
    {
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $before = [
            StatisticalImport::count(), StatisticalClassifierItem::count(), StatisticalSeries::count(),
            StatisticalObservation::count(), StatisticalImportIssue::count(),
        ];

        $preview = app(StatisticalImporterRegistry::class)->forSourceFile($file)->preview($file);

        $this->assertSame($before, [
            StatisticalImport::count(), StatisticalClassifierItem::count(), StatisticalSeries::count(),
            StatisticalObservation::count(), StatisticalImportIssue::count(),
        ]);
        $this->assertSame(4, $preview->structure['sheets_total']);
        $this->assertCount(3, $preview->structure['supported_sheets']);
        $this->assertCount(1, $preview->structure['ignored_sheets']);
        $this->assertSame([2021, 2024, 2026], $preview->structure['detected_years']);
        $this->assertEqualsCanonicalizing(['flat', 'regional'], $preview->structure['topologies']);
        $this->assertSame(7, $preview->counts['commodity_occurrences']);
        $this->assertSame(5, $preview->counts['numeric_code_occurrences']);
        $this->assertSame(2, $preview->counts['rosstat_local_ag_code_occurrences']);
        $this->assertSame(3, $preview->counts['unique_commodity_codes']);
        $this->assertSame(14, $preview->counts['observation_candidates']);
        $this->assertSame(12, $preview->counts['numeric']);
        $this->assertSame(1, $preview->counts['special_footnoted']);
        $this->assertSame(1, $preview->counts['missing']);
        $this->assertSame(1, $preview->counts['ignored_territory_rows']);
        $this->assertContains('31.02.10.140', array_column($preview->sampleRecords, 'item_code'));
        $this->assertContains('05.10.10.101.АГ', array_column($preview->sampleRecords, 'item_code'));
        $this->assertContains('rosstat_local_ag', array_column($preview->sampleRecords, 'code_kind'));
        $this->assertContains('2026-01-01', array_column($preview->sampleRecords, 'period_start'));
    }

    public function test_missing_ru_block_is_reported_fatal_across_chunk_boundary(): void
    {
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeMissingRuWorkbook());
        $preview = app(StatisticalImporterRegistry::class)->forSourceFile($file)->preview($file);

        $this->assertSame(1, $preview->counts['fatal_errors']);
        $this->assertSame(1, $preview->counts['observation_candidates']);
    }
}
