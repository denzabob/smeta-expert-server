<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;

class StatisticalComparisonBasisDetector
{
    public function __construct(private readonly StatisticalNameNormalizer $normalizer)
    {
    }

    public function detect(string $text): ?string
    {
        $text = $this->normalizer->normalize($text);

        if (preg_match('/с начала года.*к соответствующему периоду (?:предыдущего|прошлого) года/u', $text)) {
            return 'year_to_date_year_over_year';
        }

        if (preg_match('/к соответствующему месяцу (?:предыдущего|прошлого) года/u', $text)) {
            return 'year_over_year';
        }

        if (preg_match('/к декабрю предыдущего года/u', $text)) {
            return 'previous_december';
        }

        if (preg_match('/к предыдущему месяцу/u', $text)) {
            return 'previous_month';
        }

        return null;
    }
}
