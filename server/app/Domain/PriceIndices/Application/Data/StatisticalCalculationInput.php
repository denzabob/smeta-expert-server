<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class StatisticalCalculationInput
{
    public function __construct(
        public string $seriesPublicId,
        public string $startPeriod,
        public string $endPeriod,
        public ?string $baseAmount,
    ) {
    }
}
