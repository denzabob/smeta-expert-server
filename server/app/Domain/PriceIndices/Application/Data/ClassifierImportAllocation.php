<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;

final readonly class ClassifierImportAllocation
{
    public function __construct(
        public StatisticalClassifierImport $import,
        public bool $reused,
    ) {}
}
