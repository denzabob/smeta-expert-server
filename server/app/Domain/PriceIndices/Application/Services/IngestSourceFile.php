<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\IngestSourceFileData;
use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileDuplicate;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileIngestionException;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;
use App\Domain\PriceIndices\Domain\Exceptions\XlsxValidationException;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Infrastructure\Storage\PrivateSourceFileStorage;
use App\Domain\PriceIndices\Infrastructure\Storage\StreamingFileHasher;
use App\Domain\PriceIndices\Infrastructure\Storage\XlsxTechnicalValidator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class IngestSourceFile
{
    public function __construct(
        private readonly PrivateSourceFileStorage $storage,
        private readonly XlsxTechnicalValidator $validator,
        private readonly StreamingFileHasher $hasher,
    ) {
    }

    public function execute(IngestSourceFileData $data): StatisticalSourceFile
    {
        $finalPath = null;
        $transactionStarted = false;

        try {
            $this->validateInput($data);

            $absoluteTempPath = $this->storage->absolutePath($data->temporaryFilePath);
            $validation = $this->validator->validate(
                $absoluteTempPath,
                $data->originalFilename,
                $data->mimeType
            );
            $hash = $this->hasher->hash($absoluteTempPath);

            $existing = $this->findDuplicate($data->dataset->id, $hash->sha256);

            if ($existing !== null) {
                $this->logDuplicate($data, $existing);
                throw new SourceFileDuplicate($existing);
            }

            $publicId = (string) Str::uuid();
            $finalPath = $this->finalPath($data, $publicId);

            DB::beginTransaction();
            $transactionStarted = true;

            $sourceFile = new StatisticalSourceFile();
            $sourceFile->fill([
                'dataset_id' => $data->dataset->id,
                'source_id' => $data->source?->id,
                'acquisition_method' => $data->acquisitionMethod,
                'reporting_year' => $data->reportingYear,
                'reporting_month' => $data->reportingMonth,
                'source_url' => $data->sourceUrl,
                'original_filename' => $data->originalFilename,
                'stored_path' => $finalPath,
                'storage_disk' => (string) config('price_indices.source_files.storage_disk', 'local'),
                'mime_type' => $data->mimeType,
                'file_size' => $hash->size,
                'sha256' => $hash->sha256,
                'uploaded_by_user_id' => $data->actor?->id,
                'detected_at' => now(),
                'status' => SourceFileStatus::PendingReview,
                'validation_status' => $validation->status,
                'validation_summary_json' => [
                    'technical' => [
                        'warnings' => $validation->warnings,
                    ],
                ],
                'metadata_json' => $data->metadata,
            ]);
            $sourceFile->public_id = $publicId;
            $sourceFile->save();

            $this->storage->move($data->temporaryFilePath, $finalPath);
            DB::commit();
            $transactionStarted = false;

            $this->storage->deleteIfExists($data->temporaryFilePath);

            Log::info('manual source file accepted', $this->logContext($data, $sourceFile));

            return $sourceFile->refresh();
        } catch (QueryException $exception) {
            $this->rollBackIfNeeded($transactionStarted, $exception);
            $this->cleanup($data->temporaryFilePath, $finalPath, $exception);

            if ($this->isDuplicateHashConflict($exception)) {
                $hash ??= $this->hasher->hash($this->storage->absolutePath($data->temporaryFilePath));
                $existing = $this->findDuplicate($data->dataset->id, $hash->sha256);

                if ($existing !== null) {
                    $this->logDuplicate($data, $existing);
                    throw new SourceFileDuplicate($existing, $exception);
                }
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBackIfNeeded($transactionStarted, $exception);
            $this->cleanup($data->temporaryFilePath, $finalPath, $exception);

            if ($exception instanceof XlsxValidationException) {
                Log::notice('manual source file rejected', $this->logContext($data) + [
                    'code' => $exception->errorCode->value,
                ]);
            } elseif ($exception instanceof SourceFileStorageException) {
                Log::error('source file storage failure', $this->logContext($data) + [
                    'exception' => $exception::class,
                ]);
            }

            throw $exception;
        }
    }

    private function validateInput(IngestSourceFileData $data): void
    {
        if ($data->source !== null && $data->source->dataset_id !== $data->dataset->id) {
            throw new SourceFileIngestionException(
                SourceFileErrorCode::SourceDatasetMismatch,
                'The selected source does not belong to the dataset.'
            );
        }

        if (($data->reportingYear === null) !== ($data->reportingMonth === null)
            || ($data->reportingMonth !== null && ($data->reportingMonth < 1 || $data->reportingMonth > 12))
        ) {
            throw new SourceFileIngestionException(
                SourceFileErrorCode::InvalidPeriod,
                'Reporting year and month must both be omitted or both be valid.'
            );
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $data->dataset->code) !== 1) {
            throw new SourceFileStorageException('The dataset code cannot be used for private storage.');
        }
    }

    private function finalPath(IngestSourceFileData $data, string $publicId): string
    {
        $year = $data->reportingYear === null ? 'unknown' : (string) $data->reportingYear;
        $month = $data->reportingMonth === null ? 'unknown' : sprintf('%02d', $data->reportingMonth);

        return "price-indices/source-files/{$data->dataset->code}/{$year}/{$month}/{$publicId}.xlsx";
    }

    private function findDuplicate(int $datasetId, string $sha256): ?StatisticalSourceFile
    {
        return StatisticalSourceFile::query()
            ->where('dataset_id', $datasetId)
            ->where('sha256', $sha256)
            ->first();
    }

    private function isDuplicateHashConflict(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), 'stat_files_dataset_sha_unique');
    }

    private function rollBackIfNeeded(bool $transactionStarted, Throwable $original): void
    {
        if (! $transactionStarted || DB::transactionLevel() === 0) {
            return;
        }

        try {
            DB::rollBack();
        } catch (Throwable $rollbackException) {
            Log::critical('source file database rollback failure', [
                'original_exception' => $original::class,
                'rollback_exception' => $rollbackException::class,
            ]);

            throw new RuntimeException('Unable to roll back source file ingestion.', 0, $original);
        }
    }

    private function cleanup(string $temporaryPath, ?string $finalPath, Throwable $original): void
    {
        $cleanupFailure = null;

        foreach (array_filter([$temporaryPath, $finalPath]) as $path) {
            try {
                $this->storage->deleteIfExists($path);
            } catch (Throwable $exception) {
                $cleanupFailure ??= $exception;
            }
        }

        if ($cleanupFailure !== null) {
            Log::critical('source file compensation failure', [
                'original_exception' => $original::class,
                'cleanup_exception' => $cleanupFailure::class,
            ]);

            throw new SourceFileStorageException(
                'Unable to compensate a failed source file ingestion.',
                $original
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(
        IngestSourceFileData $data,
        ?StatisticalSourceFile $sourceFile = null
    ): array {
        return [
            'dataset_public_id' => $data->dataset->public_id,
            'dataset_code' => $data->dataset->code,
            'source_public_id' => $data->source?->public_id,
            'source_file_public_id' => $sourceFile?->public_id,
            'acquisition_method' => $data->acquisitionMethod->value,
            'reporting_year' => $data->reportingYear,
            'reporting_month' => $data->reportingMonth,
            'status' => $sourceFile?->status?->value,
        ];
    }

    private function logDuplicate(
        IngestSourceFileData $data,
        StatisticalSourceFile $existing
    ): void {
        Log::notice('source file duplicate detected', $this->logContext($data, $existing));
    }
}
