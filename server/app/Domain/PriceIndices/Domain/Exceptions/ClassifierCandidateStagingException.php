<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use RuntimeException;
use Throwable;

class ClassifierCandidateStagingException extends RuntimeException
{
    /** @param array<string, int|string|bool|null> $boundedContext */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $safeMessage,
        public readonly string $stage,
        public readonly array $boundedContext = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }
}
