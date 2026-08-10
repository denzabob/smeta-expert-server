<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

class StatisticalYearDetector
{
    public function detect(string $text): ?int
    {
        preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/u', $text, $matches);
        $years = array_values(array_unique(array_map('intval', $matches[0] ?? [])));

        return count($years) === 1 ? $years[0] : null;
    }

    public function isAmbiguous(string $text): bool
    {
        preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/u', $text, $matches);

        return count(array_unique($matches[0] ?? [])) > 1;
    }
}
