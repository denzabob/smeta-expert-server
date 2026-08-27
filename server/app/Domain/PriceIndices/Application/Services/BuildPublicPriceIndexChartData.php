<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\PublicPriceIndexChartData;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Database\Eloquent\Collection;

final readonly class BuildPublicPriceIndexChartData
{
    public function __construct(
        private PublicIndexFormatter $formatter,
        private PublicIndexFamilyRegistry $families,
    ) {}

    /** @param Collection<int, StatisticalObservation> $observations */
    public function execute(
        StatisticalPublicSeriesPage $page,
        Collection $observations,
    ): PublicPriceIndexChartData {
        $points = $observations->values()->map(fn (StatisticalObservation $observation, int $index): array => [
            'period' => $observation->period_start->format('Y-m'),
            'display_period' => $this->formatter->period($observation->period_start, true),
            'value' => $observation->value,
            'sequence' => $index + 1,
        ])->all();

        $family = $this->families->forDataset($page->dataset);

        return new PublicPriceIndexChartData(
            series: [
                'slug' => (string) $page->slug,
                'title' => (string) $page->classifierItem?->name,
                'code' => $family->code === PublicIndexFamilyRegistry::PRODUCER_PRICES
                    ? (string) $page->classifierItem?->item_code
                    : null,
            ],
            points: $points,
            limits: [
                'first_available_period' => $points[0]['period'] ?? null,
                'last_available_period' => $points[array_key_last($points)]['period'] ?? null,
                'calculator_max_range_months' => (int) config('price_indices.public_calculation.max_period_months', 120),
            ],
        );
    }
}
