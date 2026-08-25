<?php

namespace App\Domain\PriceIndices\Application\Data;

use Carbon\CarbonImmutable;

final readonly class PublicIndexSearchResult
{
    public const TYPE_STATISTICAL_SERIES = 'statistical_series';

    public const TYPE_CLASSIFIER_NODE = 'classifier_node';

    public function __construct(
        public string $type,
        public string $code,
        public string $name,
        public ?string $classifierLabel,
        public ?string $localClassifierCode,
        public ?string $providerCodeKind,
        public ?string $semanticLevel,
        public ?string $classifierVersionPublicId,
        public ?string $classifierVersionLabel,
        public bool $hasRosstatData,
        public ?string $statisticalSlug,
        public ?CarbonImmutable $periodFrom,
        public ?CarbonImmutable $periodTo,
        public ?string $changePercent,
        public ?string $coefficient,
    ) {}

    public function isStatisticalSeries(): bool
    {
        return $this->type === self::TYPE_STATISTICAL_SERIES;
    }
}
