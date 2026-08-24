<?php

namespace App\Jobs;

use App\Domain\PriceIndices\Application\Services\RefreshPublicStatisticalSeriesPages;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshPriceIndicesPublicPagesJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 3_600;

    public int $backoff = 60;

    public function __construct(
        public readonly string $datasetPublicId,
        public readonly string $importPublicId,
    ) {}

    public function handle(RefreshPublicStatisticalSeriesPages $refresh): void
    {
        $pointer = StatisticalDatasetActiveImport::query()
            ->whereHas('dataset', fn (Builder $query) => $query->where('public_id', $this->datasetPublicId))
            ->with(['import:id,public_id'])
            ->first();

        $activeImportPublicId = $pointer?->import?->public_id;
        if ($activeImportPublicId !== $this->importPublicId) {
            Log::info('Price indices public snapshot refresh skipped because publication is no longer active.',
                $this->context() + ['active_import_public_id' => $activeImportPublicId]
            );

            return;
        }

        Log::info('Price indices public snapshot refresh started.', $this->context());

        try {
            $result = $refresh->execute($this->datasetPublicId);
        } catch (Throwable $exception) {
            Log::error('Price indices public snapshot refresh failed.', $this->context() + [
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        Log::info('Price indices public snapshot refresh completed.', $this->context() + [
            'series_scanned' => $result->seriesScanned,
            'indexable' => $result->indexable,
            'non_indexable' => $result->nonIndexable,
            'created' => $result->created,
            'updated' => $result->updated,
            'unchanged' => $result->unchanged,
            'failed' => $result->failed,
            'stale' => $result->stale,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Price indices public snapshot refresh exhausted its retries.', $this->context() + [
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }

    /** @return array{dataset_public_id: string, import_public_id: string} */
    private function context(): array
    {
        return [
            'dataset_public_id' => $this->datasetPublicId,
            'import_public_id' => $this->importPublicId,
        ];
    }
}
