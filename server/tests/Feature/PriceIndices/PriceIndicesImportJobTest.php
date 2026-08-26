<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\CreateStatisticalImport;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Jobs\RunStatisticalImportJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\PriceIndices\Support\BuildsStatisticalImportWorkbook;
use Tests\TestCase;

class PriceIndicesImportJobTest extends TestCase
{
    use BuildsStatisticalImportWorkbook;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake($this->importTestDisk);
        Config::set('price_indices.imports.chunk_rows', 2);
        Config::set('price_indices.imports.db_batch_size', 2);
    }

    public function test_job_runs_pending_import_to_ready_without_publishing(): void
    {
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $import = app(CreateStatisticalImport::class)->execute($dataset, $file);
        $job = new RunStatisticalImportJob($import->public_id);

        $this->app->call([$job, 'handle']);
        $import->refresh();

        $this->assertSame(StatisticalImportStatus::ReadyForPublish, $import->status);
        $this->assertSame(14, $import->observations()->count());
        $this->assertNotNull($import->successful_dedupe_key);
        $this->assertNull($import->published_at);
        $this->assertSame(0, StatisticalDatasetActiveImport::query()->where('dataset_id', $dataset->id)->count());
        $this->assertSame(1, $job->tries);
        $this->assertSame(3600, $job->timeout);
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }

    public function test_controlled_parser_failure_marks_failed_and_cleans_observations(): void
    {
        Config::set('price_indices.imports.db_batch_size', 1);
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeFormulaWorkbook());
        $import = app(CreateStatisticalImport::class)->execute($dataset, $file);

        $this->app->call([new RunStatisticalImportJob($import->public_id), 'handle']);
        $import->refresh();

        $this->assertSame(StatisticalImportStatus::Failed, $import->status);
        $this->assertSame('workbook_validation_failed', $import->failure_code);
        $this->assertSame(0, StatisticalObservation::query()->where('import_id', $import->id)->count());
        $this->assertGreaterThan(0, $import->issues()->where('severity', 'fatal')->count());
    }

    public function test_unexpected_registry_failure_marks_failed_and_is_rethrown(): void
    {
        $dataset = StatisticalDataset::factory()->create(['code' => 'unsupported_dataset']);
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $import = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $file->id,
            'importer_code' => 'unsupported_importer',
            'importer_version' => '1.0.0',
            'status' => StatisticalImportStatus::Pending,
        ]);

        try {
            $this->app->call([new RunStatisticalImportJob($import->public_id), 'handle']);
            $this->fail('Unsupported importer unexpectedly ran.');
        } catch (PriceIndicesInvariantViolation) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(StatisticalImportStatus::Failed, $import->refresh()->status);
        $this->assertSame('unexpected_import_error', $import->failure_code);
    }
}
