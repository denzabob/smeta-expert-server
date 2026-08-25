<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use RuntimeException;

class ClassifierItemMappingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
