<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ConsumerPriceIndexPreview
{
    /**
     * @param  array<string, int>  $observationsPerSeries
     * @param  list<array{code: string, message: string}>  $warnings
     * @param  list<array<string, string>>  $sourceNotes
     */
    public function __construct(
        public string $datasetCandidate,
        public string $classifier,
        public string $artifactSha256,
        public int $series,
        public string $firstPeriod,
        public string $lastPeriod,
        public array $observationsPerSeries,
        public int $totalObservations,
        public string $territory,
        public string $frequency,
        public string $comparisonBasis,
        public string $unit,
        public array $warnings,
        public array $sourceNotes,
        public ?string $sourceUpdatedLabel,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dataset_candidate' => $this->datasetCandidate,
            'classifier' => $this->classifier,
            'artifact_sha256' => $this->artifactSha256,
            'series' => $this->series,
            'first_period' => $this->firstPeriod,
            'last_period' => $this->lastPeriod,
            'observations_per_series' => $this->observationsPerSeries,
            'total_observations' => $this->totalObservations,
            'territory' => $this->territory,
            'frequency' => $this->frequency,
            'comparison_basis' => $this->comparisonBasis,
            'unit' => $this->unit,
            'warnings' => $this->warnings,
            'source_notes' => $this->sourceNotes,
            'source_updated_label' => $this->sourceUpdatedLabel,
        ];
    }
}
