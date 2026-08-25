<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierValidationSummary
{
    /**
     * @param  list<ClassifierValidationIssue>  $fatalErrors
     * @param  list<ClassifierValidationIssue>  $warnings
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public array $fatalErrors,
        public array $warnings,
        public array $metrics,
    ) {}
}
