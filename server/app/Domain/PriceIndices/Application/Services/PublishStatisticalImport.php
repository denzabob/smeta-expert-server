<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportConflict;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportLifecycle;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PublishStatisticalImport
{
    public function __construct(private readonly StatisticalImportLifecycle $lifecycle)
    {
    }

    public function execute(StatisticalImport $import, User $actor): StatisticalImport
    {
        try {
            return DB::transaction(function () use ($import, $actor): StatisticalImport {
                $target = StatisticalImport::query()
                    ->with('sourceFile')
                    ->lockForUpdate()
                    ->findOrFail($import->id);

                if ($target->sourceFile->dataset_id !== $target->dataset_id
                    || $target->sourceFile->status !== SourceFileStatus::Active
                ) {
                    throw new PriceIndicesInvariantViolation(
                        'Only an import of the current active source file can be published.'
                    );
                }

                $this->lifecycle->transition($target, StatisticalImportStatus::Published);

                $pointer = StatisticalDatasetActiveImport::query()
                    ->where('dataset_id', $target->dataset_id)
                    ->lockForUpdate()
                    ->first();
                $previous = null;

                if ($pointer !== null) {
                    $previous = StatisticalImport::query()
                        ->lockForUpdate()
                        ->findOrFail($pointer->import_id);

                    if ($previous->dataset_id !== $target->dataset_id
                        || $previous->status !== StatisticalImportStatus::Published
                    ) {
                        throw new PriceIndicesInvariantViolation(
                            'The current active import pointer is inconsistent.'
                        );
                    }

                    $this->lifecycle->transition($previous, StatisticalImportStatus::Superseded);
                    $previous->superseded_at = now();
                    $previous->save();
                }

                $publishedAt = now();
                $target->published_at = $publishedAt;
                $target->published_by_user_id = $actor->id;
                $target->supersedes_import_id = $previous?->id;
                $target->save();

                if ($pointer === null) {
                    $pointer = new StatisticalDatasetActiveImport();
                    $pointer->dataset_id = $target->dataset_id;
                }

                $pointer->import_id = $target->id;
                $pointer->published_by_user_id = $actor->id;
                $pointer->published_at = $publishedAt;
                $pointer->save();

                return $target->refresh();
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062
                && (str_contains($exception->getMessage(), 'stat_active_imports_dataset_unique')
                    || str_contains($exception->getMessage(), 'stat_active_imports_import_unique'))
            ) {
                throw StatisticalImportConflict::concurrentPublication($exception);
            }

            throw $exception;
        }
    }
}
