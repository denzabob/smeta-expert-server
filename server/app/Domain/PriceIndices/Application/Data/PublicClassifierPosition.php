<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class PublicClassifierPosition
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isCurrent,
        public bool $hasRosstatData,
        public ?string $statisticalSlug,
    ) {}
}
