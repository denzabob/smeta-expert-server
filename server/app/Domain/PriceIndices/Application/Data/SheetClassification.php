<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class SheetClassification
{
    /**
     * @param array<string, int> $monthColumns
     * @param list<string> $errors
     */
    public function __construct(
        public string $name,
        public int $position,
        public int $totalRows,
        public ?int $year,
        public ?string $comparisonBasis,
        public ?string $topology,
        public ?int $headerRow,
        public array $monthColumns,
        public bool $supported,
        public ?string $ignoredReason,
        public array $errors = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
