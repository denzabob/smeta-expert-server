<?php

namespace App\Domain\PriceIndices\Application\Data;

use DateTimeImmutable;

final readonly class DownloadedClassifierArtifact
{
    public function __construct(
        public string $temporaryPath,
        public string $resolvedUrl,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
        public ?string $etag,
        public ?DateTimeImmutable $lastModifiedAt,
    ) {}
}
