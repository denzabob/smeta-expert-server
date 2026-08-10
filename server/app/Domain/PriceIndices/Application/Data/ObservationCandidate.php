<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind;

final readonly class ObservationCandidate
{
    public function __construct(
        public string $itemCode,
        public CommodityCodeKind $codeKind,
        public string $itemName,
        public string $territoryCode,
        public string $periodStart,
        public ?string $value,
        public ?string $missingReason,
        public string $sheetName,
        public int $sourceRow,
        public string $sourceColumn,
        public string $sourceCellAddress,
        public string $sourceValueRaw,
        public ?string $footnoteMarker,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'item_code' => $this->itemCode,
            'code_kind' => $this->codeKind->value,
            'item_name' => $this->itemName,
            'territory' => $this->territoryCode,
            'period_start' => $this->periodStart,
            'value' => $this->value,
            'missing_reason' => $this->missingReason,
            'sheet' => $this->sheetName,
            'row' => $this->sourceRow,
            'column' => $this->sourceColumn,
            'cell' => $this->sourceCellAddress,
            'raw' => $this->sourceValueRaw,
            'footnote_marker' => $this->footnoteMarker,
        ];
    }
}
