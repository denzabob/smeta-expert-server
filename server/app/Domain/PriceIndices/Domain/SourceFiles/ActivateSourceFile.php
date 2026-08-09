<?php

namespace App\Domain\PriceIndices\Domain\SourceFiles;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileActivationConflict;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ActivateSourceFile
{
    public function __construct(private readonly SourceFileLifecycle $lifecycle)
    {
    }

    public function execute(StatisticalSourceFile $sourceFile, User $actor): StatisticalSourceFile
    {
        try {
            return DB::transaction(function () use ($sourceFile, $actor): StatisticalSourceFile {
                $target = StatisticalSourceFile::query()
                    ->lockForUpdate()
                    ->findOrFail($sourceFile->getKey());

                if ($target->reporting_year === null || $target->reporting_month === null) {
                    throw new PriceIndicesInvariantViolation(
                        'A source file must have a complete reporting period before activation.'
                    );
                }

                $this->lifecycle->transition($target, SourceFileStatus::Active);

                $pointer = StatisticalDatasetActiveFile::query()
                    ->where('dataset_id', $target->dataset_id)
                    ->where('reporting_year', $target->reporting_year)
                    ->where('reporting_month', $target->reporting_month)
                    ->lockForUpdate()
                    ->first();

                $currentFile = null;

                if ($pointer !== null) {
                    $currentFile = StatisticalSourceFile::query()
                        ->lockForUpdate()
                        ->findOrFail($pointer->source_file_id);

                    if ($currentFile->dataset_id !== $target->dataset_id
                        || $currentFile->reporting_year !== $target->reporting_year
                        || $currentFile->reporting_month !== $target->reporting_month
                    ) {
                        throw new PriceIndicesInvariantViolation(
                            'The current active pointer does not match its source file dataset and period.'
                        );
                    }

                    $this->lifecycle->transition($currentFile, SourceFileStatus::Superseded);
                    $currentFile->save();
                }

                $activatedAt = now();
                $target->activated_by_user_id = $actor->getKey();
                $target->activated_at = $activatedAt;
                $target->supersedes_file_id = $currentFile?->getKey();
                $target->save();

                if ($pointer === null) {
                    $pointer = new StatisticalDatasetActiveFile();
                    $pointer->dataset_id = $target->dataset_id;
                    $pointer->reporting_year = $target->reporting_year;
                    $pointer->reporting_month = $target->reporting_month;
                }

                $pointer->source_file_id = $target->getKey();
                $pointer->activated_by_user_id = $actor->getKey();
                $pointer->activated_at = $activatedAt;
                $pointer->save();

                return $target->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isActivePointerUniqueConflict($exception)) {
                throw SourceFileActivationConflict::concurrent($exception);
            }

            throw $exception;
        }
    }

    private function isActivePointerUniqueConflict(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && str_contains($exception->getMessage(), 'stat_active_dataset_period_unique');
    }
}
