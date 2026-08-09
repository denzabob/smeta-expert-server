<?php

namespace App\Domain\PriceIndices\Domain\SourceFiles;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileTransitionNotAllowed;

class SourceFileLifecycle
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        SourceFileStatus::PendingReview->value => [
            SourceFileStatus::Approved->value,
            SourceFileStatus::Rejected->value,
        ],
        SourceFileStatus::Approved->value => [
            SourceFileStatus::Active->value,
        ],
        SourceFileStatus::Active->value => [
            SourceFileStatus::Superseded->value,
        ],
    ];

    public function canTransition(SourceFileStatus $from, SourceFileStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(StatisticalSourceFile $file, SourceFileStatus $to): StatisticalSourceFile
    {
        $from = $file->status;

        if (! $this->canTransition($from, $to)) {
            throw SourceFileTransitionNotAllowed::between($from, $to);
        }

        $file->status = $to;

        return $file;
    }
}
