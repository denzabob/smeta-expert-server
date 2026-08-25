<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierExpectedProfile
{
    /**
     * @param  list<string>  $requiredSections
     * @param  array<string, int>|null  $exactLevelCounts
     */
    public function __construct(
        public array $requiredSections,
        public int $minimumDigitalNodes,
        public ?int $exactSectionsCount = null,
        public ?int $exactDigitalNodesCount = null,
        public ?int $exactTotalNodesCount = null,
        public ?array $exactLevelCounts = null,
    ) {}
}
