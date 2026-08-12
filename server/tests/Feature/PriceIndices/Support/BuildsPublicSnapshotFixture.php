<?php

namespace Tests\Feature\PriceIndices\Support;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use DateTimeImmutable;

trait BuildsPublicSnapshotFixture
{
    use BuildsUserCalculationFixture;

    /** @return array<string, mixed> */
    private function publicSnapshotFixture(
        ?array $values = null,
        string $itemCode = '31.02.10.140',
        string $itemName = 'Наборы кухонной мебели',
        string $comparisonBasis = 'previous_month',
    ): array {
        return $this->calculationFixture(
            $values ?? $this->monthlySnapshotValues('2025-01', '2025-12'),
            $itemCode,
            $itemName,
            true,
            $comparisonBasis,
        );
    }

    /** @return array<string, string> */
    private function monthlySnapshotValues(string $from, string $to, string $value = '100.0000000000'): array
    {
        $values = [];
        $cursor = new DateTimeImmutable($from.'-01');
        $end = new DateTimeImmutable($to.'-01');
        while ($cursor <= $end) {
            $values[$cursor->format('Y-m')] = $value;
            $cursor = $cursor->modify('first day of next month');
        }

        return $values;
    }

    /** @param array<string, string|null> $values */
    private function addObservations(
        StatisticalImport $import,
        StatisticalSeries $series,
        array $values,
    ): void {
        foreach ($values as $period => $value) {
            StatisticalObservation::factory()->create([
                'import_id' => $import->id,
                'series_id' => $series->id,
                'source_file_id' => $import->source_file_id,
                'period_start' => $period.'-01',
                'value' => $value,
                'missing_reason' => $value === null ? 'ellipsis' : null,
            ]);
        }
    }

    private function addSeriesForItem(array $fixture, StatisticalClassifierItem $item): StatisticalSeries
    {
        return StatisticalSeries::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'indicator_id' => $fixture['indicator']->id,
            'classifier_item_id' => $item->id,
            'territory_id' => $fixture['territory']->id,
        ]);
    }
}
