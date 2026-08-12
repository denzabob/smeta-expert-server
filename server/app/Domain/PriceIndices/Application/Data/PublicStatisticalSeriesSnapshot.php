<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use DateTimeInterface;

final readonly class PublicStatisticalSeriesSnapshot
{
    public function __construct(
        public int $datasetId,
        public int $importId,
        public int $seriesId,
        public int $classifierItemId,
        public int $sourceFileId,
        public ?string $slug,
        public PublicSeriesIndexabilityStatus $status,
        public ?string $periodFrom,
        public ?string $periodTo,
        public int $observationsCount,
        public int $factorsCount,
        public ?string $coefficientRaw,
        public ?string $coefficient,
        public ?string $changePercentRaw,
        public ?string $changePercent,
        public ?string $minIndexValue,
        public ?string $minIndexPeriod,
        public ?string $maxIndexValue,
        public ?string $maxIndexPeriod,
        public DateTimeInterface $generatedAt,
        public ?DateTimeInterface $sourcePublishedAt,
    ) {
    }

    public function isIndexable(): bool
    {
        return $this->status === PublicSeriesIndexabilityStatus::Indexable;
    }

    public function withStatus(PublicSeriesIndexabilityStatus $status, ?string $slug = null): self
    {
        return new self(
            $this->datasetId,
            $this->importId,
            $this->seriesId,
            $this->classifierItemId,
            $this->sourceFileId,
            $slug,
            $status,
            $this->periodFrom,
            $this->periodTo,
            $this->observationsCount,
            $this->factorsCount,
            $this->coefficientRaw,
            $this->coefficient,
            $this->changePercentRaw,
            $this->changePercent,
            $this->minIndexValue,
            $this->minIndexPeriod,
            $this->maxIndexValue,
            $this->maxIndexPeriod,
            $this->generatedAt,
            $this->sourcePublishedAt,
        );
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'import_id' => $this->importId,
            'series_id' => $this->seriesId,
            'classifier_item_id' => $this->classifierItemId,
            'source_file_id' => $this->sourceFileId,
            'slug' => $this->slug,
            'is_indexable' => $this->isIndexable(),
            'indexability_status' => $this->status->value,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'observations_count' => $this->observationsCount,
            'factors_count' => $this->factorsCount,
            'coefficient_raw' => $this->coefficientRaw,
            'coefficient' => $this->coefficient,
            'change_percent_raw' => $this->changePercentRaw,
            'change_percent' => $this->changePercent,
            'min_index_value' => $this->minIndexValue,
            'min_index_period' => $this->minIndexPeriod,
            'max_index_value' => $this->maxIndexValue,
            'max_index_period' => $this->maxIndexPeriod,
            'source_published_at' => $this->sourcePublishedAt,
        ];
    }
}
