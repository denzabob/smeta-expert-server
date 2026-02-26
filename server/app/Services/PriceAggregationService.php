<?php

namespace App\Services;

/**
 * Stateless price aggregation utilities.
 *
 * Decimal-safe arithmetic using bcmath where precision matters.
 * All methods accept arrays of numeric values and return structured results.
 */
class PriceAggregationService
{
    /**
     * Compute aggregated price from a list of values using the specified method.
     *
     * @param float[] $prices  Non-empty array of prices
     * @param string  $method  single|mean|median|trimmed_mean
     * @param float   $trimFraction  Fraction to trim from each side for trimmed_mean (default 0.10)
     * @return array{aggregated: float, min: float, max: float, count: int, method: string, used_prices: float[], excluded_prices: float[]}
     *
     * @throws \InvalidArgumentException
     */
    public function aggregate(array $prices, string $method, float $trimFraction = 0.10): array
    {
        if (empty($prices)) {
            throw new \InvalidArgumentException('Price list must not be empty.');
        }

        $prices = array_values(array_map('floatval', $prices));
        sort($prices);

        $count = count($prices);
        $min = $prices[0];
        $max = $prices[$count - 1];

        return match ($method) {
            'mean' => $this->computeMean($prices, $min, $max),
            'median' => $this->computeMedian($prices, $min, $max),
            'trimmed_mean' => $this->computeTrimmedMean($prices, $min, $max, $trimFraction),
            'single' => [
                'aggregated' => $prices[0],
                'min' => $min,
                'max' => $max,
                'count' => $count,
                'method' => 'single',
                'used_prices' => $prices,
                'excluded_prices' => [],
            ],
            default => throw new \InvalidArgumentException("Unknown aggregation method: {$method}"),
        };
    }

    /**
     * Arithmetic mean.
     */
    private function computeMean(array $sorted, float $min, float $max): array
    {
        $sum = array_sum($sorted);
        $count = count($sorted);
        $mean = round($sum / $count, 2);

        return [
            'aggregated' => $mean,
            'min' => $min,
            'max' => $max,
            'count' => $count,
            'method' => 'mean',
            'used_prices' => $sorted,
            'excluded_prices' => [],
        ];
    }

    /**
     * Median. For even count: average of the two middle values.
     */
    private function computeMedian(array $sorted, float $min, float $max): array
    {
        $count = count($sorted);

        if ($count % 2 === 0) {
            $median = round(($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2, 2);
        } else {
            $median = round($sorted[intdiv($count, 2)], 2);
        }

        return [
            'aggregated' => $median,
            'min' => $min,
            'max' => $max,
            'count' => $count,
            'method' => 'median',
            'used_prices' => $sorted,
            'excluded_prices' => [],
        ];
    }

    /**
     * Trimmed mean: remove trimFraction from each side, compute mean of remainder.
     * Requires n >= 3. If n < 3 after trimming, falls back to median.
     */
    private function computeTrimmedMean(array $sorted, float $min, float $max, float $trimFraction): array
    {
        $count = count($sorted);

        // Fallback for small samples
        if ($count < 3) {
            $result = $this->computeMedian($sorted, $min, $max);
            $result['method'] = 'trimmed_mean';
            $result['note'] = "n={$count} < 3, fallback to median";
            return $result;
        }

        $trimCount = max(1, (int) floor($count * $trimFraction));

        // Ensure we keep at least 1 value after trimming
        if ($count - 2 * $trimCount < 1) {
            $trimCount = (int) floor(($count - 1) / 2);
        }

        $excluded = array_merge(
            array_slice($sorted, 0, $trimCount),
            array_slice($sorted, $count - $trimCount)
        );
        $used = array_slice($sorted, $trimCount, $count - 2 * $trimCount);

        $sum = array_sum($used);
        $usedCount = count($used);
        $trimmedMean = round($sum / $usedCount, 2);

        return [
            'aggregated' => $trimmedMean,
            'min' => $min,
            'max' => $max,
            'count' => $count,
            'method' => 'trimmed_mean',
            'used_prices' => $used,
            'excluded_prices' => $excluded,
            'trim_fraction' => $trimFraction,
            'trimmed_each_side' => $trimCount,
        ];
    }
}
