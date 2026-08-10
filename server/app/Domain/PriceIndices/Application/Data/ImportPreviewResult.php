<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ImportPreviewResult
{
    /** @param array<string, mixed> $workbook @param array<string, mixed> $structure @param array<string, mixed> $counts @param list<array<string, mixed>> $sampleRecords */
    public function __construct(
        public array $workbook,
        public array $structure,
        public array $counts,
        public array $sampleRecords,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workbook' => $this->workbook,
            'structure' => $this->structure,
            'counts' => $this->counts,
            'sample_records' => $this->sampleRecords,
        ];
    }
}
