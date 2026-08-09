<?php

namespace App\Domain\PriceIndices\Application\Data;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\AcquisitionMethod;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Models\User;

final readonly class IngestSourceFileData
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public StatisticalDataset $dataset,
        public ?StatisticalSource $source,
        public AcquisitionMethod $acquisitionMethod,
        public ?int $reportingYear,
        public ?int $reportingMonth,
        public ?string $sourceUrl,
        public string $originalFilename,
        public string $temporaryFilePath,
        public ?string $mimeType,
        public ?User $actor,
        public ?array $metadata = null,
    ) {
    }
}
