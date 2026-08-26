<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\IngestSourceFileData;
use App\Domain\PriceIndices\Application\Services\ConsumerPriceIndexPersistenceObserver;
use App\Domain\PriceIndices\Application\Services\CreateStatisticalImport;
use App\Domain\PriceIndices\Application\Services\ImportConsumerPriceIndexArtifact;
use App\Domain\PriceIndices\Application\Services\PreviewConsumerPriceIndexWorkbook;
use App\Domain\PriceIndices\Application\Services\ResolveClassifierMappingContext;
use App\Domain\PriceIndices\Application\Services\ResolveOrReuseStatisticalSourceFile;
use App\Domain\PriceIndices\Application\Services\StatisticalImporterRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierItemMappingException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use App\Domain\PriceIndices\Infrastructure\Import\ConsumerPriceIndicesRfMonthlyImporter;
use App\Domain\PriceIndices\Infrastructure\Import\ProducerPriceIndicesByProductImporter;
use App\Jobs\RunStatisticalImportJob;
use Database\Seeders\ConsumerPriceIndicesDatasetSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\PriceIndices\Support\BuildsConsumerPriceIndexWorkbook;
use Tests\TestCase;

class ConsumerPriceIndexImportTest extends TestCase
{
    use BuildsConsumerPriceIndexWorkbook;
    use DatabaseTransactions;

    private string $disk = 'cpi_import_test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->disk);
        Config::set('price_indices.source_files.storage_disk', $this->disk);
        Config::set('price_indices.imports.db_batch_size', 10);
    }

    protected function tearDown(): void
    {
        $this->cleanupConsumerPriceIndexWorkbooks();

        parent::tearDown();
    }

    public function test_dataset_seeder_and_dispatch_are_family_aware_without_changing_ppi_identity(): void
    {
        $this->seed(ConsumerPriceIndicesDatasetSeeder::class);
        $dataset = $this->cpiDataset();

        $this->assertSame('rosstat', $dataset->provider_code);
        $this->assertSame('index', $dataset->data_kind);
        $this->assertSame('monthly', $dataset->frequency);
        $this->assertSame('rosstat_cpi_aggregate', $dataset->classifier_code);
        $this->assertTrue($dataset->is_enabled);
        $this->assertFalse($dataset->automatic_check_enabled);

        $dataset->update(['name' => 'Сохранённое оператором имя']);
        $this->seed(ConsumerPriceIndicesDatasetSeeder::class);
        $this->assertSame('Сохранённое оператором имя', $dataset->refresh()->name);

        $cpiFile = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $registry = app(StatisticalImporterRegistry::class);
        $this->assertInstanceOf(ConsumerPriceIndicesRfMonthlyImporter::class, $registry->forSourceFile($cpiFile));
        $cpiImport = app(CreateStatisticalImport::class)->execute($dataset, $cpiFile);
        $this->assertSame('consumer_price_indices_rf_monthly', $cpiImport->importer_code);
        $this->assertSame('1.0.0', $cpiImport->importer_version);

        $ppiDataset = StatisticalDataset::factory()->create(['code' => 'producer_price_indices_by_product']);
        $ppiFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $ppiDataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $this->assertInstanceOf(ProducerPriceIndicesByProductImporter::class, $registry->forSourceFile($ppiFile));
        $ppiImport = app(CreateStatisticalImport::class)->execute($ppiDataset, $ppiFile);
        $this->assertSame('producer_price_indices_by_product', $ppiImport->importer_code);
        $this->assertSame('1.0.0', $ppiImport->importer_version);

        $unknownDataset = StatisticalDataset::factory()->create(['code' => 'unknown_statistical_family']);
        $unknownFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $unknownDataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        try {
            app(CreateStatisticalImport::class)->execute($unknownDataset, $unknownFile);
            $this->fail('Unknown dataset unexpectedly received an importer identity.');
        } catch (PriceIndicesInvariantViolation) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, StatisticalImport::query()->where('dataset_id', $unknownDataset->id)->count());
    }

    public function test_import_persists_validated_snapshot_atomically_without_publication_or_okpd2_mapping(): void
    {
        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $publicPagesBefore = DB::table('statistical_public_series_pages')->count();

        $result = app(ImportConsumerPriceIndexArtifact::class)->execute($source);
        $import = StatisticalImport::query()->where('public_id', $result->importPublicId)->sole();

        $this->assertFalse($result->reused);
        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $import->status);
        $this->assertSame('consumer_price_indices_rf_monthly', $result->datasetCode);
        $this->assertSame(4, $result->seriesCount);
        $this->assertSame(76, $result->observationsCount);
        $this->assertSame('1991-01-01', $result->firstPeriod);
        $this->assertSame('1992-07-01', $result->lastPeriod);
        $this->assertSame('consumer_price_indices_workbook', $result->parserCode);
        $this->assertSame('1.0.0', $result->parserVersion);
        $this->assertNull($import->published_at);
        $this->assertSame($publicPagesBefore, DB::table('statistical_public_series_pages')->count());

        $items = StatisticalClassifierItem::query()
            ->where('dataset_id', $dataset->id)
            ->orderBy('id')
            ->get();
        $this->assertSame([
            'all_items_and_services',
            'food_products',
            'non_food_products',
            'services',
        ], $items->pluck('item_code')->all());
        $this->assertSame(['rosstat_cpi_aggregate'], $items->pluck('classifier_code')->unique()->values()->all());
        $this->assertTrue($items->every(fn (StatisticalClassifierItem $item): bool => $item->parent_item_id === null));
        $this->assertTrue($items->every(
            fn (StatisticalClassifierItem $item): bool => ($item->metadata_json['official_provider_code'] ?? null) === false
        ));
        $this->assertSame(0, DB::table('statistical_classifier_item_mappings')
            ->whereIn('statistical_classifier_item_id', $items->pluck('id'))
            ->count());

        $indicator = StatisticalIndicator::query()->where('dataset_id', $dataset->id)->sole();
        $territory = StatisticalTerritory::query()->where('code', 'RU')->sole();
        $this->assertSame('consumer_price_index', $indicator->code);
        $this->assertSame('Российская Федерация', $territory->name);
        $series = StatisticalSeries::query()->where('dataset_id', $dataset->id)->get();
        $this->assertCount(4, $series);
        $this->assertTrue($series->every(fn (StatisticalSeries $item): bool => $item->frequency === 'monthly'));
        $this->assertTrue($series->every(fn (StatisticalSeries $item): bool => $item->comparison_basis === 'previous_month'));
        $this->assertTrue($series->every(fn (StatisticalSeries $item): bool => $item->unit === 'percent'));

        $this->assertDatabaseHas('statistical_observations', [
            'import_id' => $import->id,
            'period_start' => '1991-01-01',
            'value' => '101.0100000000',
            'sheet_name' => '01',
            'source_row' => 6,
            'source_column' => 'B',
            'source_cell_address' => 'B6',
            'source_value_raw' => '101.01',
        ]);
        $this->assertSame('consumer_price_indices_workbook', $import->metadata_json['parser']['code']);
        $this->assertSame('https://rosstat.gov.ru/statistics/price', $import->metadata_json['source']['landing_url']);
        $this->assertSame(2, count($import->metadata_json['source_notes']));
        $this->assertSame(2, $import->issues()->where('severity', 'warning')->count());

        try {
            app(ResolveClassifierMappingContext::class)->execute('rosstat_cpi_aggregate');
            $this->fail('CPI classifier unexpectedly became compatible with canonical OKPD2 mapping.');
        } catch (ClassifierItemMappingException $exception) {
            $this->assertSame('classifier_mapping_not_supported', $exception->errorCode);
        }
    }

    public function test_generic_preview_and_job_contracts_accept_the_atomic_cpi_importer(): void
    {
        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $importer = app(StatisticalImporterRegistry::class)->forSourceFile($source);

        $preview = $importer->preview($source);
        $this->assertSame('consumer_price_indices_rf_monthly', $preview->workbook['importer_code']);
        $this->assertCount(4, $preview->structure['supported_sheets']);
        $this->assertSame(76, $preview->counts['observation_candidates']);
        $this->assertSame(0, $preview->counts['fatal_errors']);

        $import = app(CreateStatisticalImport::class)->execute($dataset, $source);
        $this->app->call([new RunStatisticalImportJob($import->public_id), 'handle']);

        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $import->refresh()->status);
        $this->assertSame(76, $import->observations()->count());
        $this->assertSame(0, DB::table('statistical_public_series_pages')->count());
    }

    public function test_same_artifact_reuses_successful_import_without_duplicate_rows(): void
    {
        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $service = app(ImportConsumerPriceIndexArtifact::class);

        $first = $service->execute($source);
        $counts = $this->domainCounts($dataset);
        $second = $service->execute($source);

        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);
        $this->assertSame($first->importPublicId, $second->importPublicId);
        $this->assertSame($counts, $this->domainCounts($dataset));
        $this->assertSame(1, $dataset->imports()->count());
    }

    public function test_ready_equivalent_is_reused_even_if_a_later_attempt_failed(): void
    {
        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $service = app(ImportConsumerPriceIndexArtifact::class);
        $ready = $service->execute($source);
        StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $source->id,
            'importer_code' => 'consumer_price_indices_rf_monthly',
            'importer_version' => '1.0.0',
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Failed,
            'failure_code' => 'synthetic_later_failure',
        ]);

        $reused = $service->execute($source);

        $this->assertTrue($reused->reused);
        $this->assertSame($ready->importPublicId, $reused->importPublicId);
        $this->assertSame(2, $dataset->imports()->count());
    }

    #[DataProvider('failurePointProvider')]
    public function test_each_injected_persistence_failure_rolls_back_cpi_domain_rows(string $failurePoint): void
    {
        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $observer = new class($failurePoint) extends ConsumerPriceIndexPersistenceObserver
        {
            public function __construct(private readonly string $failurePoint) {}

            public function reached(string $point, int $processedObservations = 0): void
            {
                if ($point === $this->failurePoint) {
                    throw new RuntimeException("Injected CPI failure at {$point}: {$processedObservations}");
                }
            }
        };
        $this->app->instance(ConsumerPriceIndexPersistenceObserver::class, $observer);

        try {
            app(ImportConsumerPriceIndexArtifact::class)->execute($source);
            $this->fail("Failure point [{$failurePoint}] was not reached.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($failurePoint, $exception->getMessage());
        }

        $import = $dataset->imports()->sole();
        $this->assertSame(StatisticalImportStatus::Failed, $import->status);
        $this->assertSame('unexpected_import_error', $import->failure_code);
        $this->assertSame(0, StatisticalClassifierItem::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(0, StatisticalIndicator::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(0, StatisticalSeries::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(0, $import->observations()->count());
        $this->assertSame(0, $import->issues()->count());
    }

    /** @return array<string, array{string}> */
    public static function failurePointProvider(): array
    {
        return [
            'after references' => [ConsumerPriceIndexPersistenceObserver::AFTER_REFERENCE_RESOLUTION],
            'after series' => [ConsumerPriceIndexPersistenceObserver::AFTER_SERIES_RESOLUTION],
            'part way through observations' => [ConsumerPriceIndexPersistenceObserver::AFTER_OBSERVATION_BATCH],
            'before ready transition' => [ConsumerPriceIndexPersistenceObserver::BEFORE_READY_TRANSITION],
        ];
    }

    public function test_failed_attempt_allows_new_attempt_and_future_artifact_reuses_logical_dimensions(): void
    {
        $dataset = $this->seededDataset();
        $firstSource = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $failOnce = new class extends ConsumerPriceIndexPersistenceObserver
        {
            private bool $failed = false;

            public function reached(string $point, int $processedObservations = 0): void
            {
                if (! $this->failed && $point === self::BEFORE_READY_TRANSITION) {
                    $this->failed = true;
                    throw new RuntimeException('Injected one-time CPI failure.');
                }
            }
        };
        $this->app->instance(ConsumerPriceIndexPersistenceObserver::class, $failOnce);

        try {
            app(ImportConsumerPriceIndexArtifact::class)->execute($firstSource);
            $this->fail('The first CPI attempt unexpectedly succeeded.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->app->instance(ConsumerPriceIndexPersistenceObserver::class, new ConsumerPriceIndexPersistenceObserver);
        $retry = app(ImportConsumerPriceIndexArtifact::class)->execute($firstSource);
        $this->assertFalse($retry->reused);
        $this->assertSame(2, $dataset->imports()->count());
        $this->assertSame([
            StatisticalImportStatus::Failed,
            StatisticalImportStatus::ReadyForPublish,
        ], $dataset->imports()->orderBy('attempt_no')->pluck('status')->all());

        $futurePath = $this->writeConsumerPriceIndexWorkbook(function (Spreadsheet $book): void {
            foreach (['01', '02', '03', '04'] as $offset => $sheetName) {
                $book->getSheetByName($sheetName)?->setCellValue('C13', 102 + $offset + 0.08);
            }
        });
        $futureSource = $this->activeSource($dataset, $futurePath, 8);
        $future = app(ImportConsumerPriceIndexArtifact::class)->execute($futureSource);

        $this->assertNotSame($firstSource->sha256, $futureSource->sha256);
        $this->assertNotSame($retry->importPublicId, $future->importPublicId);
        $this->assertSame(80, $future->observationsCount);
        $this->assertSame('1992-08-01', $future->lastPeriod);
        $this->assertSame(4, StatisticalClassifierItem::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(4, StatisticalSeries::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(76, StatisticalImport::query()
            ->where('public_id', $retry->importPublicId)
            ->sole()
            ->observations()
            ->count());
    }

    public function test_failed_future_import_preserves_shared_dimensions_and_historical_observations(): void
    {
        $dataset = $this->seededDataset();
        $firstSource = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        $first = app(ImportConsumerPriceIndexArtifact::class)->execute($firstSource);
        $futurePath = $this->writeConsumerPriceIndexWorkbook(function (Spreadsheet $book): void {
            foreach (['01', '02', '03', '04'] as $offset => $sheetName) {
                $book->getSheetByName($sheetName)?->setCellValue('C13', 102 + $offset + 0.08);
            }
        });
        $futureSource = $this->activeSource($dataset, $futurePath, 8);
        $observer = new class extends ConsumerPriceIndexPersistenceObserver
        {
            public function reached(string $point, int $processedObservations = 0): void
            {
                if ($point === self::AFTER_OBSERVATION_BATCH) {
                    throw new RuntimeException('Injected future CPI failure.');
                }
            }
        };
        $this->app->instance(ConsumerPriceIndexPersistenceObserver::class, $observer);

        try {
            app(ImportConsumerPriceIndexArtifact::class)->execute($futureSource);
            $this->fail('The future CPI attempt unexpectedly succeeded.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $historical = StatisticalImport::query()->where('public_id', $first->importPublicId)->sole();
        $failed = StatisticalImport::query()->where('source_file_id', $futureSource->id)->sole();
        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $historical->status);
        $this->assertSame(76, $historical->observations()->count());
        $this->assertSame(StatisticalImportStatus::Failed, $failed->status);
        $this->assertSame(0, $failed->observations()->count());
        $this->assertSame(4, StatisticalClassifierItem::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(4, StatisticalSeries::query()->where('dataset_id', $dataset->id)->count());
    }

    public function test_changed_physical_artifact_fails_closed_before_domain_persistence(): void
    {
        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $this->writeConsumerPriceIndexWorkbook());
        Storage::disk($this->disk)->append($source->stored_path, 'tampered');

        try {
            app(ImportConsumerPriceIndexArtifact::class)->execute($source);
            $this->fail('Changed CPI artifact unexpectedly imported.');
        } catch (StatisticalImportParsingFailed $exception) {
            $this->assertSame('source_file_integrity_mismatch', $exception->failureCode);
        }

        $import = $dataset->imports()->sole();
        $this->assertSame(StatisticalImportStatus::Failed, $import->status);
        $this->assertSame('source_file_integrity_mismatch', $import->failure_code);
        $this->assertSame(0, StatisticalClassifierItem::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(0, $import->observations()->count());
    }

    public function test_opt_in_source_registration_reuses_same_dataset_sha_without_changing_global_ingestion(): void
    {
        $dataset = $this->seededDataset();
        $fixture = $this->writeConsumerPriceIndexWorkbook();
        $bytes = file_get_contents($fixture);
        self::assertIsString($bytes);
        Storage::disk($this->disk)->put('price-indices/tmp/cpi-first.upload', $bytes);
        Storage::disk($this->disk)->put('price-indices/tmp/cpi-second.upload', $bytes);

        $first = app(ResolveOrReuseStatisticalSourceFile::class)->execute(
            $this->ingestData($dataset, 'price-indices/tmp/cpi-first.upload'),
        );
        $second = app(ResolveOrReuseStatisticalSourceFile::class)->execute(
            $this->ingestData($dataset, 'price-indices/tmp/cpi-second.upload'),
        );

        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);
        $this->assertSame($first->sourceFile->public_id, $second->sourceFile->public_id);
        $this->assertSame(SourceFileStatus::PendingReview, $first->sourceFile->status);
        $this->assertSame(1, $dataset->sourceFiles()->count());
        Storage::disk($this->disk)->assertMissing('price-indices/tmp/cpi-second.upload');
    }

    public function test_exact_operator_artifact_import_smoke_when_path_is_available(): void
    {
        $path = getenv('PRICE_INDICES_CPI_ARTIFACT_PATH');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            $this->markTestSkipped('Operator CPI artifact path is not available.');
        }

        $this->assertSame(37871, filesize($path));
        $this->assertSame(
            'ACE8990FE8358173F743987A256EAEF71501B06B5C4E5FE865B28046776EA412',
            strtoupper(hash_file('sha256', $path)),
        );

        $preview = app(PreviewConsumerPriceIndexWorkbook::class)->execute($path);
        $this->assertSame(4, $preview->series);
        $this->assertSame(1708, $preview->totalObservations);
        $this->assertSame('1991-01-01', $preview->firstPeriod);
        $this->assertSame('2026-07-01', $preview->lastPeriod);

        $dataset = $this->seededDataset();
        $source = $this->activeSource($dataset, $path);
        $publicPagesBefore = DB::table('statistical_public_series_pages')->count();
        $first = app(ImportConsumerPriceIndexArtifact::class)->execute($source);
        $second = app(ImportConsumerPriceIndexArtifact::class)->execute($source);

        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);
        $this->assertSame($first->importPublicId, $second->importPublicId);
        $this->assertSame(4, $first->seriesCount);
        $this->assertSame(1708, $first->observationsCount);
        $this->assertSame('1991-01-01', $first->firstPeriod);
        $this->assertSame('2026-07-01', $first->lastPeriod);
        $this->assertSame(StatisticalImportStatus::ReadyForPublish->value, $first->importStatus);
        $this->assertSame($publicPagesBefore, DB::table('statistical_public_series_pages')->count());
    }

    private function seededDataset(): StatisticalDataset
    {
        $this->seed(ConsumerPriceIndicesDatasetSeeder::class);

        return $this->cpiDataset();
    }

    private function cpiDataset(): StatisticalDataset
    {
        return StatisticalDataset::query()->where('code', 'consumer_price_indices_rf_monthly')->sole();
    }

    private function activeSource(
        StatisticalDataset $dataset,
        string $fixturePath,
        int $reportingMonth = 7,
    ): StatisticalSourceFile {
        $storedPath = 'price-indices/cpi/'.uniqid('', true).'.xlsx';
        $bytes = file_get_contents($fixturePath);
        self::assertIsString($bytes);
        Storage::disk($this->disk)->put($storedPath, $bytes);
        $absolutePath = Storage::disk($this->disk)->path($storedPath);
        $source = StatisticalSource::factory()->create([
            'dataset_id' => $dataset->id,
            'source_page_url' => 'https://rosstat.gov.ru/statistics/price',
        ]);

        return StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'source_id' => $source->id,
            'acquisition_method' => AcquisitionMethod::ManualUpload,
            'reporting_year' => 2026,
            'reporting_month' => $reportingMonth,
            'source_url' => 'https://www.rosstat.gov.ru/storage/mediabank/ipc_mes_07-2026.xlsx',
            'original_filename' => 'ipc_mes_07-2026.xlsx',
            'storage_disk' => $this->disk,
            'stored_path' => $storedPath,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_size' => filesize($absolutePath),
            'sha256' => hash_file('sha256', $absolutePath),
            'status' => SourceFileStatus::Active,
            'validation_status' => ValidationStatus::Passed,
            'metadata_json' => [
                'landing_url' => 'https://rosstat.gov.ru/statistics/price',
                'workbook_modified' => '2026-08-11T13:18:19Z',
                'displayed_update_date' => '12.08.2026',
                'trust' => 'operator_supplied',
                'context' => 'official_research_artifact',
            ],
        ]);
    }

    private function ingestData(StatisticalDataset $dataset, string $temporaryPath): IngestSourceFileData
    {
        return new IngestSourceFileData(
            dataset: $dataset,
            source: null,
            acquisitionMethod: AcquisitionMethod::ManualUpload,
            reportingYear: 2026,
            reportingMonth: 7,
            sourceUrl: 'https://www.rosstat.gov.ru/storage/mediabank/ipc_mes_07-2026.xlsx',
            originalFilename: 'ipc_mes_07-2026.xlsx',
            temporaryFilePath: $temporaryPath,
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            actor: null,
            metadata: [
                'landing_url' => 'https://rosstat.gov.ru/statistics/price',
                'workbook_modified' => '2026-08-11T13:18:19Z',
                'displayed_update_date' => '12.08.2026',
                'trust' => 'operator_supplied',
                'context' => 'official_research_artifact',
            ],
        );
    }

    /** @return array<string, int> */
    private function domainCounts(StatisticalDataset $dataset): array
    {
        return [
            'sources' => $dataset->sourceFiles()->count(),
            'imports' => $dataset->imports()->count(),
            'items' => $dataset->classifierItems()->count(),
            'series' => $dataset->series()->count(),
            'observations' => DB::table('statistical_observations')
                ->join('statistical_imports', 'statistical_imports.id', '=', 'statistical_observations.import_id')
                ->where('statistical_imports.dataset_id', $dataset->id)
                ->count(),
        ];
    }
}
