<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;

final readonly class ResolvedClassifierItem
{
    public function __construct(
        public StatisticalClassifierItem $item,
        public bool $nameChanged
    ) {
    }
}
