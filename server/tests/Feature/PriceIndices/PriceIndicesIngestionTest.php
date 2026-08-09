<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\IngestSourceFileData;
use App\Domain\PriceIndices\Application\Services\IngestSourceFile;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileDuplicate;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileIngestionException;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Domain\PriceIndices\Infrastructure\Storage\PrivateSourceFileStorage;
use App\Domain\PriceIndices\Infrastructure\Storage\StreamingFileHasher;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\Feature\PriceIndices\Support\BuildsSyntheticXlsx;
use Tests\TestCase;

class PriceIndicesIngestionTest extends TestCase
{
    use BuildsSyntheticXlsx;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('price_indices.source_files.storage_disk', 'price_indices_test');
        Storage::fake('price_indices_test');
    }

    protected function tearDown(): void
    {
        $this->forgetSyntheticXlsxFiles();
        parent::tearDown();
    }

    public function test_successful_manual_ingestion_is_private_streamed_and_pending_review(): void
    {
        $dataset = StatisticalDataset::factory()->create(['code' => 'producer_indices']);
        $actor = User::factory()->create();
        $temporaryPath = $this->temporaryXlsx();
        $fixturePath = Storage::disk('price_indices_test')->path($temporaryPath);
        $expectedHash = hash_file('sha256', $fixturePath);

        $file = $this->ingest($dataset, $temporaryPath, actor: $actor);

        $this->assertSame(SourceFileStatus::PendingReview, $file->status);
        $this->assertSame(ValidationStatus::Passed, $file->validation_status);
        $this->assertSame($expectedHash, $file->sha256);
        $this->assertSame(filesize(Storage::disk('price_indices_test')->path($file->stored_path)), $file->file_size);
        $this->assertSame($actor->id, $file->uploaded_by_user_id);
        $this->assertStringStartsWith(
            'price-indices/source-files/producer_indices/2026/01/',
            $file->stored_path
        );
        $this->assertStringEndsWith("/{$file->public_id}.xlsx", $file->stored_path);
        $this->assertStringNotContainsString('original-report.xlsx', $file->stored_path);
        Storage::disk('price_indices_test')->assertExists($file->stored_path);
        Storage::disk('price_indices_test')->assertMissing($temporaryPath);
    }

    public function test_unknown_period_uses_unknown_private_path_segments(): void
    {
        $dataset = StatisticalDataset::factory()->create(['code' => 'unknown_period']);
        $file = $this->ingest(
            $dataset,
            $this->temporaryXlsx(),
            reportingYear: null,
            reportingMonth: null
        );

        $this->assertStringContainsString('/unknown/unknown/', $file->stored_path);
    }

    public function test_duplicate_returns_existing_public_id_and_cleans_second_temp(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $firstTemp = $this->temporaryXlsx();
        $fixture = Storage::disk('price_indices_test')->path($firstTemp);
        $secondTemp = 'price-indices/tmp/duplicate.upload';
        $stream = fopen($fixture, 'rb');
        Storage::disk('price_indices_test')->put($secondTemp, $stream);
        fclose($stream);
        $existing = $this->ingest($dataset, $firstTemp);

        try {
            $this->ingest($dataset, $secondTemp);
            $this->fail('Duplicate ingestion did not fail.');
        } catch (SourceFileDuplicate $exception) {
            $this->assertSame($existing->public_id, $exception->existingFile->public_id);
        }

        $this->assertSame(1, StatisticalSourceFile::query()->where('dataset_id', $dataset->id)->count());
        Storage::disk('price_indices_test')->assertMissing($secondTemp);
    }

    public function test_source_dataset_mismatch_and_invalid_period_clean_temp(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $source = StatisticalSource::factory()->create();
        $mismatchTemp = $this->temporaryXlsx();

        try {
            $this->ingest($dataset, $mismatchTemp, source: $source);
            $this->fail('Source/dataset mismatch did not fail.');
        } catch (SourceFileIngestionException $exception) {
            $this->assertSame(SourceFileErrorCode::SourceDatasetMismatch, $exception->errorCode);
        }
        Storage::disk('price_indices_test')->assertMissing($mismatchTemp);

        $periodTemp = $this->temporaryXlsx();

        try {
            $this->ingest($dataset, $periodTemp, reportingMonth: null);
            $this->fail('Partial reporting period did not fail.');
        } catch (SourceFileIngestionException $exception) {
            $this->assertSame(SourceFileErrorCode::InvalidPeriod, $exception->errorCode);
        }
        Storage::disk('price_indices_test')->assertMissing($periodTemp);
    }

    public function test_final_move_failure_rolls_back_db_and_cleans_all_paths(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $temporaryPath = $this->temporaryXlsx();
        $storage = Mockery::mock(PrivateSourceFileStorage::class)->makePartial();
        $storage->shouldReceive('move')->once()->andThrow(new SourceFileStorageException());
        $this->app->instance(PrivateSourceFileStorage::class, $storage);

        $this->expectException(SourceFileStorageException::class);

        try {
            $this->ingest($dataset, $temporaryPath);
        } finally {
            $this->assertSame(0, StatisticalSourceFile::query()->where('dataset_id', $dataset->id)->count());
            Storage::disk('price_indices_test')->assertMissing($temporaryPath);
            $this->assertSame([], Storage::disk('price_indices_test')->allFiles('price-indices/source-files'));
        }
    }

    public function test_db_insert_failure_cleans_temp_without_final_or_row(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $temporaryPath = $this->temporaryXlsx();
        $failOnce = true;
        StatisticalSourceFile::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('Artificial DB insert failure.');
            }
        });

        $this->expectException(RuntimeException::class);

        try {
            $this->ingest($dataset, $temporaryPath);
        } finally {
            $this->assertSame(0, StatisticalSourceFile::query()->where('dataset_id', $dataset->id)->count());
            Storage::disk('price_indices_test')->assertMissing($temporaryPath);
            $this->assertSame([], Storage::disk('price_indices_test')->allFiles('price-indices/source-files'));
        }
    }

    public function test_streaming_hasher_handles_twenty_four_megabytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'price_indices_hash_');
        $this->syntheticXlsxPaths[] = $path;
        $stream = fopen($path, 'wb');
        $context = hash_init('sha256');

        for ($index = 0; $index < 24; $index++) {
            $chunk = hash('sha256', (string) $index, true).str_repeat(chr(65 + ($index % 26)), 1_048_544);
            fwrite($stream, $chunk);
            hash_update($context, $chunk);
        }

        fclose($stream);
        $result = app(StreamingFileHasher::class)->hash($path);

        $this->assertSame(24 * 1_048_576, $result->size);
        $this->assertSame(hash_final($context), $result->sha256);
    }

    private function temporaryXlsx(): string
    {
        $fixture = $this->makeSyntheticXlsx();
        $path = 'price-indices/tmp/'.uniqid('upload_', true).'.upload';
        $stream = fopen($fixture, 'rb');
        $stored = Storage::disk('price_indices_test')->put($path, $stream);
        fclose($stream);
        $this->assertTrue($stored);

        return $path;
    }

    private function ingest(
        StatisticalDataset $dataset,
        string $temporaryPath,
        ?StatisticalSource $source = null,
        ?User $actor = null,
        ?int $reportingYear = 2026,
        ?int $reportingMonth = 1,
    ): StatisticalSourceFile {
        return app(IngestSourceFile::class)->execute(new IngestSourceFileData(
            dataset: $dataset,
            source: $source,
            acquisitionMethod: AcquisitionMethod::ManualUpload,
            reportingYear: $reportingYear,
            reportingMonth: $reportingMonth,
            sourceUrl: null,
            originalFilename: 'original-report.xlsx',
            temporaryFilePath: $temporaryPath,
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            actor: $actor,
            metadata: ['comment' => 'test'],
        ));
    }
}
