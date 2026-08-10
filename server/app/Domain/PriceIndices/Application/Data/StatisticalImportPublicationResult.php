<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;

final readonly class StatisticalImportPublicationResult
{
    public function __construct(
        public StatisticalImport $import,
        public ?string $previousImportPublicId,
    ) {
    }
}
