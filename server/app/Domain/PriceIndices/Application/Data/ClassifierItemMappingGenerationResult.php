<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierItemMappingGenerationResult
{
    public function __construct(
        public string $classifierCode,
        public string $activeVersionPublicId,
        public string $activeVersionLabel,
        public int $totalCompatibleItems,
        public int $exactConfirmed,
        public int $ambiguousNeedsReview,
        public int $localRosstat,
        public int $unmapped,
        public int $manualPreserved,
    ) {}
}
