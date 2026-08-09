<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use App\Domain\PriceIndices\Domain\Enums\SourceFileErrorCode;
use DomainException;

class XlsxValidationException extends DomainException
{
    public function __construct(
        public readonly SourceFileErrorCode $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
