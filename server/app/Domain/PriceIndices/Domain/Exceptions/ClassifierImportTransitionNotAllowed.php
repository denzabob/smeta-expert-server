<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use RuntimeException;

class ClassifierImportTransitionNotAllowed extends RuntimeException
{
    public static function between(ClassifierImportStatus $from, ClassifierImportStatus $to): self
    {
        return new self("Classifier import transition [{$from->value} -> {$to->value}] is not allowed.");
    }
}
