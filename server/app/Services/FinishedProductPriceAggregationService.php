<?php

namespace App\Services;

use App\Models\FinishedProductAggregationProfile;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;

class FinishedProductPriceAggregationService
{
    public function __construct(
        private PriceAggregationService $priceAggregationService,
    ) {}

    public function aggregateForSpecification(int|FinishedProductSpecification $specification): array
    {
        $specification = $specification instanceof FinishedProductSpecification
            ? $specification
            : FinishedProductSpecification::query()->findOrFail($specification);
        $profile = $this->resolveProfile($specification->id);

        $sources = FinishedProductPriceSource::query()
            ->forSpecification($specification->id)
            ->with(['supplier', 'priceListVersion'])
            ->get()
            ->filter(function (FinishedProductPriceSource $source) use ($profile) {
                return $this->isEligible($source, $profile);
            })
            ->values();

        $prices = $sources
            ->pluck('price_per_m2_normalized')
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();

        $minimumSources = $profile->minimum_sources_count;
        if (empty($prices) || ($minimumSources !== null && count($prices) < $minimumSources)) {
            return [
                'finished_product_specification_id' => $specification->id,
                'computed_price_per_m2' => null,
                'method' => $profile->method,
                'source_count' => count($prices),
                'min_price' => !empty($prices) ? min($prices) : null,
                'max_price' => !empty($prices) ? max($prices) : null,
                'used_source_ids' => [],
                'used_sources' => [],
            ];
        }

        $aggregated = $this->priceAggregationService->aggregate($prices, $profile->method);
        $usedSourceIds = $sources->pluck('id')->all();

        return [
            'finished_product_specification_id' => $specification->id,
            'computed_price_per_m2' => $aggregated['aggregated'],
            'method' => $profile->method,
            'source_count' => $aggregated['count'],
            'min_price' => $aggregated['min'],
            'max_price' => $aggregated['max'],
            'used_source_ids' => $usedSourceIds,
            'used_sources' => $sources->map(function (FinishedProductPriceSource $source) {
                return [
                    'id' => $source->id,
                    'supplier_id' => $source->supplier_id,
                    'price_list_version_id' => $source->price_list_version_id,
                    'price_per_m2_normalized' => $source->price_per_m2_normalized !== null
                        ? (float) $source->price_per_m2_normalized
                        : null,
                    'status' => $source->status,
                ];
            })->all(),
        ];
    }

    private function resolveProfile(int $specificationId): FinishedProductAggregationProfile
    {
        return FinishedProductAggregationProfile::firstOrCreate(
            ['finished_product_specification_id' => $specificationId],
            [
                'method' => FinishedProductAggregationProfile::METHOD_MEDIAN,
                'include_only_active' => true,
                'exclude_stale' => true,
            ]
        );
    }

    private function isEligible(FinishedProductPriceSource $source, FinishedProductAggregationProfile $profile): bool
    {
        if ($source->price_per_m2_normalized === null) {
            return false;
        }

        if ($source->status === FinishedProductPriceSource::STATUS_INVALID) {
            return false;
        }

        if ($source->status === FinishedProductPriceSource::STATUS_SUPERSEDED) {
            return false;
        }

        if ($profile->include_only_active && $source->status !== FinishedProductPriceSource::STATUS_ACTIVE) {
            return false;
        }

        if ($profile->exclude_stale && $source->status === FinishedProductPriceSource::STATUS_STALE) {
            return false;
        }

        return true;
    }
}
