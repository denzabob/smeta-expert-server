<?php

namespace App\Domain\PriceIndices\Infrastructure\Parsing;

final readonly class ParsedWordPart
{
    /**
     * @param  list<RawClassifierNode>  $nodes
     * @param  list<string>  $sections
     */
    public function __construct(
        public array $nodes,
        public array $sections,
        public int $rowsCount,
        public int $notesCount,
    ) {}
}
