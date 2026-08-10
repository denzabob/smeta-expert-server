<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\QueuedStatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class QueueStatisticalImportPreview
{
    public function __construct(
        private readonly StatisticalImporterRegistry $registry,
        private readonly StatisticalImportPreviewCacheKey $cacheKey,
        private readonly ExpireStatisticalImportPreviewIfNeeded $expire,
        private readonly DispatchStatisticalImportPreviewJob $dispatch,
    ) {
    }

    public function execute(
        StatisticalSourceFile $sourceFile,
        User $actor,
    ): QueuedStatisticalImportPreview {
        if ($sourceFile->status !== SourceFileStatus::Active) {
            throw new PriceIndicesApiException(
                'source_file_not_active',
                409,
                'Only an active source file can be previewed.',
            );
        }
        try {
            if (! Storage::disk($sourceFile->storage_disk)->exists($sourceFile->stored_path)) {
                throw new PriceIndicesApiException(
                    'source_file_missing',
                    404,
                    'Source file binary was not found.',
                );
            }
        } catch (PriceIndicesApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new PriceIndicesApiException(
                'preview_internal_error',
                500,
                'Unable to inspect the source file storage.',
                $exception,
            );
        }

        try {
            $importer = $this->registry->forSourceFile($sourceFile);
        } catch (PriceIndicesInvariantViolation $exception) {
            throw new PriceIndicesApiException(
                'unsupported_dataset',
                422,
                'No importer supports this source file dataset.',
                $exception,
            );
        }
        $cacheKey = $this->cacheKey->forSourceFile($sourceFile, $importer->code(), $importer->version());

        try {
            $result = Cache::lock(
                $this->cacheKey->lockName($cacheKey),
                (int) config('price_indices.imports.preview_lock_ttl', 300),
            )->block(
                (int) config('price_indices.imports.preview_lock_wait_seconds', 5),
                function () use ($sourceFile, $actor, $importer, $cacheKey): QueuedStatisticalImportPreview {
                    $existing = StatisticalImportPreview::query()
                        ->where('cache_key', $cacheKey)
                        ->whereIn('status', [
                            StatisticalImportPreviewStatus::Pending,
                            StatisticalImportPreviewStatus::Running,
                            StatisticalImportPreviewStatus::Ready,
                        ])
                        ->latest('id')
                        ->first();

                    if ($existing !== null) {
                        $existing = $this->expire->execute($existing);
                        if ($existing->status === StatisticalImportPreviewStatus::Ready) {
                            return new QueuedStatisticalImportPreview($existing, false, true, true, 200);
                        }
                        if (in_array($existing->status, [
                            StatisticalImportPreviewStatus::Pending,
                            StatisticalImportPreviewStatus::Running,
                        ], true)) {
                            return new QueuedStatisticalImportPreview($existing, false, true, true, 202);
                        }
                    }

                    $preview = StatisticalImportPreview::query()->create([
                        'dataset_id' => $sourceFile->dataset_id,
                        'source_file_id' => $sourceFile->id,
                        'importer_code' => $importer->code(),
                        'importer_version' => $importer->version(),
                        'status' => StatisticalImportPreviewStatus::Pending,
                        'cache_key' => $cacheKey,
                        'requested_by_user_id' => $actor->id,
                    ]);

                    return new QueuedStatisticalImportPreview($preview, true, false, false, 202);
                },
            );
        } catch (LockTimeoutException $exception) {
            $existing = StatisticalImportPreview::query()
                ->where('cache_key', $cacheKey)
                ->whereIn('status', [
                    StatisticalImportPreviewStatus::Pending,
                    StatisticalImportPreviewStatus::Running,
                    StatisticalImportPreviewStatus::Ready,
                ])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                $existing = $this->expire->execute($existing);
                if ($existing->status === StatisticalImportPreviewStatus::Ready) {
                    return new QueuedStatisticalImportPreview($existing, false, true, true, 200);
                }
                if (in_array($existing->status, [
                    StatisticalImportPreviewStatus::Pending,
                    StatisticalImportPreviewStatus::Running,
                ], true)) {
                    return new QueuedStatisticalImportPreview($existing, false, true, true, 202);
                }
            }

            throw new PriceIndicesApiException(
                'preview_already_running',
                409,
                'An equivalent preview request is being created.',
                $exception,
            );
        }

        if ($result->queued) {
            $this->dispatch->execute($result->preview);
        }

        return $result;
    }
}
