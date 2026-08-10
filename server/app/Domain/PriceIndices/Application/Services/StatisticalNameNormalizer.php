<?php

namespace App\Domain\PriceIndices\Application\Services;

class StatisticalNameNormalizer
{
    public function normalize(string $value): string
    {
        $normalized = str_replace("\u{00A0}", ' ', trim($value));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower($normalized, 'UTF-8');
    }
}
