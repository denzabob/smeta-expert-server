<?php

namespace App\Services;

use App\Models\FinishedProductComputedPrice;
use App\Models\FinishedProductSpecification;

class RefreshFinishedProductComputedPriceService
{
    public function __construct(
        private FinishedProductPriceAggregationService $aggregationService,
    ) {}

    public function refresh(int|FinishedProductSpecification $specification): FinishedProductComputedPrice
    {
        $specification = $specification instanceof FinishedProductSpecification
            ? $specification
            : FinishedProductSpecification::query()->findOrFail($specification);
        $summary = $this->aggregationService->aggregateForSpecification($specification);

        $projection = FinishedProductComputedPrice::firstOrNew([
            'finished_product_specification_id' => $specification->id,
        ]);

        $projection->fill([
            'computed_price_per_m2' => $summary['computed_price_per_m2'],
            'method' => $summary['method'],
            'source_count' => $summary['source_count'],
            'min_price' => $summary['min_price'],
            'max_price' => $summary['max_price'],
            'computed_at' => now(),
            'metadata' => [
                'used_source_ids' => $summary['used_source_ids'],
            ],
        ]);
        $projection->save();

        return $projection->fresh();
    }
}
