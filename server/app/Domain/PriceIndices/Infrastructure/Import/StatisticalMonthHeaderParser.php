<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use InvalidArgumentException;

class StatisticalMonthHeaderParser
{
    private const MONTHS = [
        'январь' => 1, 'февраль' => 2, 'март' => 3, 'апрель' => 4,
        'май' => 5, 'июнь' => 6, 'июль' => 7, 'август' => 8,
        'сентябрь' => 9, 'октябрь' => 10, 'ноябрь' => 11, 'декабрь' => 12,
    ];

    public function __construct(private readonly StatisticalNameNormalizer $normalizer)
    {
    }

    /** @param array<string, mixed> $cells @return array<string, int> */
    public function parse(array $cells): array
    {
        $result = [];
        $seen = [];

        foreach ($cells as $column => $value) {
            $normalized = $this->normalizer->normalize((string) $value);
            $normalized = preg_replace('/1\)$/u', '', $normalized) ?? $normalized;
            $month = self::MONTHS[$normalized] ?? null;
            if ($month === null) {
                continue;
            }
            if (isset($seen[$month])) {
                throw new InvalidArgumentException('duplicate_month');
            }
            $seen[$month] = true;
            $result[$column] = $month;
        }

        $months = array_values($result);
        if ($months !== [] && $months !== range(1, count($months))) {
            throw new InvalidArgumentException('invalid_month_order_or_gap');
        }

        return $result;
    }
}
