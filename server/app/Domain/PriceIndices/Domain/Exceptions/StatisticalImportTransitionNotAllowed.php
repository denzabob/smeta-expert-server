<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use DomainException;

class StatisticalImportTransitionNotAllowed extends DomainException
{
    public static function between(
        StatisticalImportStatus $from,
        StatisticalImportStatus $to
    ): self {
        return new self("Statistical import cannot transition from {$from->value} to {$to->value}.");
    }
}
