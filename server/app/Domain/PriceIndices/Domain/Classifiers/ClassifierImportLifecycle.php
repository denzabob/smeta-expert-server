<?php

namespace App\Domain\PriceIndices\Domain\Classifiers;

use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierImportTransitionNotAllowed;

class ClassifierImportLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        ClassifierImportStatus::Pending->value => [
            ClassifierImportStatus::Parsing->value,
        ],
        ClassifierImportStatus::Parsing->value => [
            ClassifierImportStatus::Validating->value,
            ClassifierImportStatus::Failed->value,
        ],
        ClassifierImportStatus::Validating->value => [
            ClassifierImportStatus::Ready->value,
            ClassifierImportStatus::Failed->value,
        ],
    ];

    public function canTransition(ClassifierImportStatus $from, ClassifierImportStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(
        StatisticalClassifierImport $import,
        ClassifierImportStatus $to,
    ): StatisticalClassifierImport {
        $from = $import->status;

        if (! $this->canTransition($from, $to)) {
            throw ClassifierImportTransitionNotAllowed::between($from, $to);
        }

        $import->status = $to;

        if ($to === ClassifierImportStatus::Parsing && $import->started_at === null) {
            $import->started_at = now();
        }

        if (in_array($to, [ClassifierImportStatus::Ready, ClassifierImportStatus::Failed], true)) {
            $import->finished_at = now();
        }

        return $import;
    }
}
