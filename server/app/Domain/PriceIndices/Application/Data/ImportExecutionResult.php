<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ImportExecutionResult
{
    /** @param list<int> $years */
    public function __construct(
        public string $importPublicId,
        public string $importerCode,
        public string $importerVersion,
        public int $sheetsProcessed,
        public array $years,
        public int $commodityOccurrences,
        public int $uniqueItems,
        public int $observationsCreated,
        public int $missingCount,
        public int $warnings,
        public int $errors,
        public float $elapsedSeconds,
        public int $peakMemoryBytes,
        public int $chunkRows,
        public int $dbBatchSize,
        public float $workbookParseSeconds,
        public float $dbInsertSeconds,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
