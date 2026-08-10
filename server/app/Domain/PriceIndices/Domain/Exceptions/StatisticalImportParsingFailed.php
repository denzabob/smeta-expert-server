<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use RuntimeException;

class StatisticalImportParsingFailed extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
