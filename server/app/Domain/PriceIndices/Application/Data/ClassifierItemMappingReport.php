<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierItemMappingReport
{
    /**
     * @param  array<string, int>  $mappingTypes
     * @param  array<string, int>  $reviewStatuses
     * @param  list<array<string, string|null>>  $conflicts
     */
    public function __construct(
        public string $classifierCode,
        public string $activeVersionPublicId,
        public string $activeVersionLabel,
        public int $totalCompatibleItems,
        public int $mappedItems,
        public int $manualDecisions,
        public array $mappingTypes,
        public array $reviewStatuses,
        public array $conflicts,
        public int $conflictLimit,
    ) {}
}
