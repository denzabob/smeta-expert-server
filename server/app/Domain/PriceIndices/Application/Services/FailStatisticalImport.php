<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportLifecycle;
use Illuminate\Support\Facades\DB;

class FailStatisticalImport
{
    public function __construct(private readonly StatisticalImportLifecycle $lifecycle)
    {
    }

    public function execute(
        StatisticalImport $import,
        string $failureCode,
        string $failureMessage
    ): StatisticalImport {
        return DB::transaction(function () use (
            $import,
            $failureCode,
            $failureMessage
        ): StatisticalImport {
            $target = StatisticalImport::query()->lockForUpdate()->findOrFail($import->id);
            $this->lifecycle->transition($target, StatisticalImportStatus::Failed);
            $failedAt = now();
            $target->failure_code = $failureCode;
            $target->failure_message = $failureMessage;
            $target->failed_at = $failedAt;
            $target->finished_at = $failedAt;
            $target->save();

            return $target->refresh();
        });
    }
}
