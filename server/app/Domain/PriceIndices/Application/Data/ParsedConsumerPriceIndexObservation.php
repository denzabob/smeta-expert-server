<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ParsedConsumerPriceIndexObservation
{
    public function __construct(
        public string $periodStart,
        public string $value,
        public string $sourceValueRaw,
        public string $sheetName,
        public int $sourceRow,
        public string $sourceColumn,
        public string $sourceCellAddress,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'value' => $this->value,
            'source_value_raw' => $this->sourceValueRaw,
            'sheet' => $this->sheetName,
            'row' => $this->sourceRow,
            'column' => $this->sourceColumn,
            'cell' => $this->sourceCellAddress,
        ];
    }
}
