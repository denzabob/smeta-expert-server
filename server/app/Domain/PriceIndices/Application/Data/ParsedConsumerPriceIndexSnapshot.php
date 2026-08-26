<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ParsedConsumerPriceIndexSnapshot
{
    public const DATASET_CODE = 'consumer_price_indices_rf_monthly';

    public const CLASSIFIER_CODE = 'rosstat_cpi_aggregate';

    public const TERRITORY_CODE = 'RU';

    public const FREQUENCY = 'monthly';

    public const COMPARISON_BASIS = 'previous_month';

    public const UNIT = 'percent';

    /**
     * @param  list<ParsedConsumerPriceIndexSeries>  $series
     * @param  list<ConsumerPriceIndexSourceNote>  $sourceNotes
     * @param  list<array{code: string, message: string}>  $warnings
     */
    public function __construct(
        public array $series,
        public array $sourceNotes,
        public array $warnings,
        public ?string $sourceUpdatedLabel,
    ) {}

    public function firstPeriod(): ?string
    {
        return $this->series === [] ? null : $this->series[0]->firstPeriod();
    }

    public function lastPeriod(): ?string
    {
        return $this->series === [] ? null : $this->series[0]->lastPeriod();
    }

    public function totalObservations(): int
    {
        return array_sum(array_map(
            fn (ParsedConsumerPriceIndexSeries $series): int => count($series->observations),
            $this->series,
        ));
    }

    /** @return array<string, int> */
    public function observationsPerSeries(): array
    {
        $counts = [];

        foreach ($this->series as $series) {
            $counts[$series->internalKey] = count($series->observations);
        }

        return $counts;
    }
}
