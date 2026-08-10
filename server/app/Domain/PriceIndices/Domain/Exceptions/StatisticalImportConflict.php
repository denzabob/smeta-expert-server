<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use DomainException;
use Throwable;

class StatisticalImportConflict extends DomainException
{
    public static function duplicateSuccessful(Throwable $previous): self
    {
        return new self(
            'This source file was already imported successfully by this importer version.',
            0,
            $previous
        );
    }

    public static function concurrentPublication(Throwable $previous): self
    {
        return new self('The active statistical import changed concurrently.', 0, $previous);
    }
}
