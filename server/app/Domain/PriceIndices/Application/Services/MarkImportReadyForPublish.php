<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportConflict;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MarkImportReadyForPublish
{
    public function __construct(private readonly StatisticalImportLifecycle $lifecycle)
    {
    }

    public function execute(StatisticalImport $import): StatisticalImport
    {
        try {
            return DB::transaction(function () use ($import): StatisticalImport {
                $target = StatisticalImport::query()->lockForUpdate()->findOrFail($import->id);

                if ($target->errors_count > 0
                    || $target->issues()
                        ->where('severity', StatisticalImportIssueSeverity::Fatal->value)
                        ->exists()
                ) {
                    throw new PriceIndicesInvariantViolation(
                        'An import with errors or fatal issues cannot become ready for publication.'
                    );
                }

                $this->lifecycle->transition($target, StatisticalImportStatus::ReadyForPublish);
                $readyAt = now();
                $target->successful_dedupe_key = hash('sha256', implode('|', [
                    (string) $target->source_file_id,
                    $target->importer_code,
                    $target->importer_version,
                ]));
                $target->ready_at = $readyAt;
                $target->finished_at = $readyAt;
                $target->save();

                return $target->refresh();
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062
                && str_contains($exception->getMessage(), 'successful_dedupe_key')
            ) {
                throw StatisticalImportConflict::duplicateSuccessful($exception);
            }

            throw $exception;
        }
    }
}
