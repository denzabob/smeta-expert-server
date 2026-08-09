<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use DomainException;
use Throwable;

class SourceFileActivationConflict extends DomainException
{
    public static function concurrent(Throwable $previous): self
    {
        return new self('A source file is already active for this dataset and period.', 0, $previous);
    }
}
