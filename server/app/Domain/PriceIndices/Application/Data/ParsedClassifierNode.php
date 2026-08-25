<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;

final readonly class ParsedClassifierNode
{
    /** @param array<string, int|string|bool|null>|null $metadata */
    public function __construct(
        public string $code,
        public string $name,
        public string $normalizedName,
        public ClassifierSemanticLevel $semanticLevel,
        public int $formalDepth,
        public int $sourceOrder,
        public ?string $parentCode,
        public ?string $notes = null,
        public ?array $metadata = null,
    ) {}
}
