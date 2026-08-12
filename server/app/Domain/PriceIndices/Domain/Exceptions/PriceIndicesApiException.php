<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class PriceIndicesApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        ?Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, $previous);
    }
}
