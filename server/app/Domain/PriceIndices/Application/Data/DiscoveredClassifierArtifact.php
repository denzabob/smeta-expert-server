<?php

namespace App\Domain\PriceIndices\Application\Data;

use DateTimeImmutable;

final readonly class DiscoveredClassifierArtifact
{
    public function __construct(
        public string $url,
        public string $artifactType,
        public string $originalFilename,
        public ?string $versionLabel,
        public ?DateTimeImmutable $publicationDate,
        public string $sectionTitle,
    ) {}
}
