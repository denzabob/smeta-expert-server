<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ParsedClassifierSnapshot
{
    /**
     * @param  list<ParsedClassifierNode>  $nodes
     * @param  list<ClassifierValidationIssue>  $warnings
     */
    public function __construct(
        public string $parserCode,
        public int $parserVersion,
        public int $sectionsCount,
        public int $digitalNodesCount,
        public int $totalNodesCount,
        public array $nodes,
        public array $warnings,
        public ClassifierValidationSummary $validationSummary,
    ) {}
}
