<?php

namespace App\Domain\PriceIndices\Domain\Previews;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;

final class StatisticalImportPreviewLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        StatisticalImportPreviewStatus::Pending->value => [
            StatisticalImportPreviewStatus::Running->value,
            StatisticalImportPreviewStatus::Failed->value,
        ],
        StatisticalImportPreviewStatus::Running->value => [
            StatisticalImportPreviewStatus::Ready->value,
            StatisticalImportPreviewStatus::Failed->value,
        ],
        StatisticalImportPreviewStatus::Ready->value => [
            StatisticalImportPreviewStatus::Expired->value,
        ],
        StatisticalImportPreviewStatus::Failed->value => [
            StatisticalImportPreviewStatus::Expired->value,
        ],
    ];

    public function canTransition(
        StatisticalImportPreviewStatus $from,
        StatisticalImportPreviewStatus $to,
    ): bool {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(
        StatisticalImportPreview $preview,
        StatisticalImportPreviewStatus $to,
    ): StatisticalImportPreview {
        if (! $this->canTransition($preview->status, $to)) {
            throw new PriceIndicesInvariantViolation(
                "Statistical preview cannot transition from {$preview->status->value} to {$to->value}."
            );
        }

        $preview->status = $to;

        return $preview;
    }
}
