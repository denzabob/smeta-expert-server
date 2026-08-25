<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierVersionActivationResult
{
    public function __construct(
        public string $classifierCode,
        public string $classifierPublicId,
        public string $targetVersionPublicId,
        public string $targetVersionLabel,
        public string $effectiveFrom,
        public int $nodeCount,
        public ?string $previousVersionPublicId,
        public ?string $previousVersionLabel,
        public string $status,
    ) {}
}
