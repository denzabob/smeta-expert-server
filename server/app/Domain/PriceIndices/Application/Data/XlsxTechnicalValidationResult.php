<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\ValidationStatus;

final readonly class XlsxTechnicalValidationResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public ValidationStatus $status,
        public array $warnings = [],
    ) {
    }
}
