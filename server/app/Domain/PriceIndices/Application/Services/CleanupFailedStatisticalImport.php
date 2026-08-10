<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use Illuminate\Support\Facades\DB;

class CleanupFailedStatisticalImport
{
    public function execute(StatisticalImport $import): int
    {
        return DB::transaction(function () use ($import): int {
            $target = StatisticalImport::query()->lockForUpdate()->findOrFail($import->id);
            if (in_array($target->status, [
                StatisticalImportStatus::ReadyForPublish,
                StatisticalImportStatus::Published,
                StatisticalImportStatus::Superseded,
            ], true)) {
                throw new PriceIndicesInvariantViolation('Successful import observations cannot be cleaned up.');
            }

            return StatisticalObservation::query()->where('import_id', $target->id)->toBase()->delete();
        });
    }
}
