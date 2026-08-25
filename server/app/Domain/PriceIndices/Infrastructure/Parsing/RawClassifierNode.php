<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;

final class RawClassifierNode
{
    public function __construct(
        public string $code,
        public string $name,
        public string $normalizedName,
        public ClassifierSemanticLevel $semanticLevel,
        public int $formalDepth,
        public string $sectionCode,
        public string $sourcePart,
        public int $sourceRow,
        public ?string $notes = null,
    ) {}
}
