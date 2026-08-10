<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportLifecycle;
use Illuminate\Support\Facades\DB;

class BeginImportValidation
{
    public function __construct(private readonly StatisticalImportLifecycle $lifecycle)
    {
    }

    public function execute(StatisticalImport $import): StatisticalImport
    {
        return DB::transaction(function () use ($import): StatisticalImport {
            $target = StatisticalImport::query()->lockForUpdate()->findOrFail($import->id);
            $this->lifecycle->transition($target, StatisticalImportStatus::Validating);
            $target->save();

            return $target->refresh();
        });
    }
}
