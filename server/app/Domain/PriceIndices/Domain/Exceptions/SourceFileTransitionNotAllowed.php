<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use DomainException;

class SourceFileTransitionNotAllowed extends DomainException
{
    public static function between(SourceFileStatus $from, SourceFileStatus $to): self
    {
        return new self("Source file transition from {$from->value} to {$to->value} is not allowed.");
    }
}
