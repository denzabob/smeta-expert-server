<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;

final readonly class ActiveStatisticalSeries
{
    public function __construct(
        public StatisticalImport $import,
        public StatisticalSeries $series,
    ) {
    }
}
