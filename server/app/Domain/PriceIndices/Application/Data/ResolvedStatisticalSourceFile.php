<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;

final readonly class ResolvedStatisticalSourceFile
{
    public function __construct(
        public StatisticalSourceFile $sourceFile,
        public bool $reused,
    ) {}
}
