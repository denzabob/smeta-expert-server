<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class WorkbookScanResult
{
    /**
     * @param list<SheetClassification> $sheets
     * @param array<string, int|float> $counts
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $samples
     * @param list<int> $years
     */
    public function __construct(
        public array $sheets,
        public array $counts,
        public array $issues,
        public array $samples,
        public array $years,
        public float $elapsedSeconds,
        public int $peakMemoryBytes,
        public int $chunkRows,
    ) {
    }
}
