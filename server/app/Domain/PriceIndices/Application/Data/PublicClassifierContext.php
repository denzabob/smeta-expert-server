<?php

namespace App\Domain\PriceIndices\Application\Data;

use Carbon\CarbonImmutable;

final readonly class PublicClassifierContext
{
    /**
     * @param  list<PublicClassifierPosition>  $lineage
     * @param  list<PublicClassifierPosition>  $children
     */
    public function __construct(
        public string $versionLabel,
        public CarbonImmutable $effectiveFrom,
        public PublicClassifierPosition $current,
        public array $lineage,
        public array $children,
        public bool $hasMoreChildren,
    ) {}
}
