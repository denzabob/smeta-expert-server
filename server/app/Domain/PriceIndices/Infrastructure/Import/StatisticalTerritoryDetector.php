<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;

class StatisticalTerritoryDetector
{
    public function __construct(private readonly StatisticalNameNormalizer $normalizer)
    {
    }

    public function isRussianFederation(mixed $value): bool
    {
        return $this->normalizer->normalize((string) $value) === 'российская федерация';
    }

    public function titleImpliesRussianFederation(string $value): bool
    {
        $normalized = $this->normalizer->normalize($value);

        return str_contains($normalized, 'российская федерация')
            || str_contains($normalized, 'российской федерации')
            || str_contains($normalized, 'российской федеpации');
    }
}
