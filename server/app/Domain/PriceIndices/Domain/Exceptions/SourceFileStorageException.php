<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use RuntimeException;
use Throwable;

class SourceFileStorageException extends RuntimeException
{
    public function __construct(string $message = 'Unable to store the source file.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
