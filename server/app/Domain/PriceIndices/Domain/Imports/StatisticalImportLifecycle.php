<?php

namespace App\Domain\PriceIndices\Domain\Imports;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportTransitionNotAllowed;

class StatisticalImportLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        StatisticalImportStatus::Pending->value => [
            StatisticalImportStatus::Importing->value,
            StatisticalImportStatus::Failed->value,
        ],
        StatisticalImportStatus::Importing->value => [
            StatisticalImportStatus::Validating->value,
            StatisticalImportStatus::Failed->value,
        ],
        StatisticalImportStatus::Validating->value => [
            StatisticalImportStatus::ReadyForPublish->value,
            StatisticalImportStatus::Failed->value,
        ],
        StatisticalImportStatus::ReadyForPublish->value => [
            StatisticalImportStatus::Published->value,
        ],
        StatisticalImportStatus::Published->value => [
            StatisticalImportStatus::Superseded->value,
        ],
    ];

    public function canTransition(
        StatisticalImportStatus $from,
        StatisticalImportStatus $to
    ): bool {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(
        StatisticalImport $import,
        StatisticalImportStatus $to
    ): StatisticalImport {
        $from = $import->status;

        if (! $this->canTransition($from, $to)) {
            throw StatisticalImportTransitionNotAllowed::between($from, $to);
        }

        $import->status = $to;

        return $import;
    }
}
