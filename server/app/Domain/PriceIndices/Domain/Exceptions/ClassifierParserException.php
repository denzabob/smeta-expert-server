<?php

namespace App\Domain\PriceIndices\Domain\Exceptions;

use App\Domain\PriceIndices\Application\Data\ClassifierValidationIssue;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationSummary;
use RuntimeException;
use Throwable;

class ClassifierParserException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ClassifierValidationSummary $validationSummary,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<string, int|string|bool|null> $context */
    public static function fatal(
        string $code,
        string $message,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            $code,
            $message,
            new ClassifierValidationSummary(
                fatalErrors: [new ClassifierValidationIssue($code, $message, $context)],
                warnings: [],
                metrics: [],
            ),
            $previous,
        );
    }
}
