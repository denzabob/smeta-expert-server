<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierValidationIssue
{
    /** @param array<string, int|string|bool|null> $context */
    public function __construct(
        public string $code,
        public string $message,
        public array $context = [],
    ) {}
}
