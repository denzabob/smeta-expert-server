<?php

namespace App\Services\MaterialDimensions;

class DimensionParseContext
{
    public function __construct(
        public readonly string $rawText,
        public readonly string $normalizedText,
        public readonly ?string $materialType,
        public readonly ?string $source,
        public readonly array $metadata = []
    ) {
    }
}
