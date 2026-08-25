<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierParserIdentity
{
    public function __construct(
        public string $code,
        public int $version,
    ) {}
}
