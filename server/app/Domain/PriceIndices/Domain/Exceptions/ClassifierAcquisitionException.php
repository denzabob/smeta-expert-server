<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use RuntimeException;
use Throwable;

class ClassifierAcquisitionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
