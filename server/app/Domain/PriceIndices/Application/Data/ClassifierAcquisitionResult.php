<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;

final readonly class ClassifierAcquisitionResult
{
    public function __construct(
        public StatisticalClassifier $classifier,
        public StatisticalClassifierSourceFile $sourceFile,
        public string $resolvedUrl,
        public bool $reused,
    ) {}
}
