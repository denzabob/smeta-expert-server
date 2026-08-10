<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\QueuedStatisticalImportPreview;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RetryStatisticalImportPreview
{
    public function __construct(
        private readonly StatisticalImporterRegistry $registry,
        private readonly StatisticalImportPreviewCacheKey $cacheKey,
        private readonly ExpireStatisticalImportPreviewIfNeeded $expire,
        private readonly DispatchStatisticalImportPreviewJob $dispatch,
    ) {
    }

    public function execute(
        StatisticalImportPreview $preview,
        User $actor,
    ): QueuedStatisticalImportPreview {
        $preview = $this->expire->execute($preview->loadMissing('sourceFile'));
        if (! in_array($preview->status, [
            StatisticalImportPreviewStatus::Failed,
            StatisticalImportPreviewStatus::Expired,
        ], true)) {
            throw new PriceIndicesApiException(
                'preview_retry_not_allowed',
                409,
                'Only a failed or expired preview can be retried.',
            );
        }
        if ($preview->sourceFile->status !== SourceFileStatus::Active) {
            throw new PriceIndicesApiException(
                'source_file_not_active',
                409,
                'The source file is no longer active.',
            );
        }
        try {
            if (! Storage::disk($preview->sourceFile->storage_disk)->exists($preview->sourceFile->stored_path)) {
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
            $importer = $this->registry->forSourceFile($preview->sourceFile);
        } catch (PriceIndicesInvariantViolation $exception) {
            throw new PriceIndicesApiException(
                'unsupported_dataset',
                422,
                'No importer supports this source file dataset.',
                $exception,
            );
        }
        if ($importer->code() !== $preview->importer_code || $importer->version() !== $preview->importer_version) {
            throw new PriceIndicesApiException(
                'preview_retry_not_allowed',
                409,
                'The original preview importer identity is no longer available.',
            );
        }

        try {
            $retry = Cache::lock(
                $this->cacheKey->lockName($preview->cache_key),
                (int) config('price_indices.imports.preview_lock_ttl', 300),
            )->block(
                (int) config('price_indices.imports.preview_lock_wait_seconds', 5),
                function () use ($preview, $actor): StatisticalImportPreview {
                    $active = StatisticalImportPreview::query()
                        ->where('cache_key', $preview->cache_key)
                        ->where('id', '!=', $preview->id)
                        ->whereIn('status', [
                            StatisticalImportPreviewStatus::Pending,
                            StatisticalImportPreviewStatus::Running,
                            StatisticalImportPreviewStatus::Ready,
                        ])
                        ->latest('id')
                        ->first();
                    if ($active !== null) {
                        $active = $this->expire->execute($active);
                        if (in_array($active->status, [
                            StatisticalImportPreviewStatus::Pending,
                            StatisticalImportPreviewStatus::Running,
                        ], true)) {
                            throw new PriceIndicesApiException(
                                'preview_already_running',
                                409,
                                'An equivalent preview is already running.',
                            );
                        }
                        if ($active->status === StatisticalImportPreviewStatus::Ready) {
                            throw new PriceIndicesApiException(
                                'preview_retry_not_allowed',
                                409,
                                'A non-expired ready preview already exists.',
                            );
                        }
                    }

                    return StatisticalImportPreview::query()->create([
                        'dataset_id' => $preview->dataset_id,
                        'source_file_id' => $preview->source_file_id,
                        'importer_code' => $preview->importer_code,
                        'importer_version' => $preview->importer_version,
                        'status' => StatisticalImportPreviewStatus::Pending,
                        'cache_key' => $preview->cache_key,
                        'requested_by_user_id' => $actor->id,
                    ]);
                },
            );
        } catch (LockTimeoutException $exception) {
            throw new PriceIndicesApiException(
                'preview_already_running',
                409,
                'An equivalent preview is already running.',
                $exception,
            );
        }

        $this->dispatch->execute($retry);

        return new QueuedStatisticalImportPreview($retry, true, false, false, 202);
    }
}
