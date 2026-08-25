<?php

namespace App\Domain\PriceIndices\Application\Data;

final readonly class ClassifierCandidatePersistenceResult
{
    /** @param array<string, float|int> $performance */
    public function __construct(
        public string $candidateKey,
        public string $classifierCode,
        public string $classifierPublicId,
        public string $sourcePublicId,
        public string $sourceSha256,
        public string $importPublicId,
        public string $versionPublicId,
        public string $versionLabel,
        public string $effectiveFrom,
        public string $status,
        public int $nodeCount,
        public bool $reused,
        public float $parseElapsedMilliseconds,
        public float $persistenceElapsedMilliseconds,
        public float $totalElapsedMilliseconds,
        public array $performance,
    ) {}
}
