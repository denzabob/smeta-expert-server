<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierCandidateStagingResult
{
    /** @param array<string, int|float|array<string, int>> $metrics */
    public function __construct(
        public string $candidateKey,
        public string $candidateFingerprint,
        public string $versionLabel,
        public string $classifierCode,
        public string $classifierPublicId,
        public string $sourcePublicId,
        public string $sourceSha256,
        public string $importPublicId,
        public int $attempt,
        public string $parserCode,
        public int $parserVersion,
        public string $status,
        public array $metrics,
        public bool $reused,
        public float $elapsedMilliseconds,
        public ?string $versionPublicId = null,
    ) {}
}
