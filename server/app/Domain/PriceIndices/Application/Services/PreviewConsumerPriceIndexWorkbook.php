<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ConsumerPriceIndexPreview;
use App\Domain\PriceIndices\Application\Data\ConsumerPriceIndexSourceNote;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Infrastructure\Import\ConsumerPriceIndicesWorkbookScanner;

final class PreviewConsumerPriceIndexWorkbook
{
    public function __construct(private readonly ConsumerPriceIndicesWorkbookScanner $scanner) {}

    public function execute(string $path): ConsumerPriceIndexPreview
    {
        $snapshot = $this->scanner->scan($path);
        $firstPeriod = $snapshot->firstPeriod();
        $lastPeriod = $snapshot->lastPeriod();

        if ($firstPeriod === null || $lastPeriod === null) {
            throw new \LogicException('A validated CPI snapshot must have a coverage range.');
        }
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new \RuntimeException('Unable to hash the CPI workbook for preview.');
        }

        return new ConsumerPriceIndexPreview(
            ParsedConsumerPriceIndexSnapshot::DATASET_CODE,
            ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
            strtoupper($sha256),
            count($snapshot->series),
            $firstPeriod,
            $lastPeriod,
            $snapshot->observationsPerSeries(),
            $snapshot->totalObservations(),
            ParsedConsumerPriceIndexSnapshot::TERRITORY_CODE,
            ParsedConsumerPriceIndexSnapshot::FREQUENCY,
            ParsedConsumerPriceIndexSnapshot::COMPARISON_BASIS,
            ParsedConsumerPriceIndexSnapshot::UNIT,
            $snapshot->warnings,
            array_map(
                fn (ConsumerPriceIndexSourceNote $note): array => $note->toArray(),
                $snapshot->sourceNotes,
            ),
            $snapshot->sourceUpdatedLabel,
        );
    }
}
