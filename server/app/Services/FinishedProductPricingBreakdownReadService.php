<?php

namespace App\Services;

use App\Models\FinishedProductAggregationProfile;
use App\Models\FinishedProductComputedPrice;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;

class FinishedProductPricingBreakdownReadService
{
    public function forSpecification(int|FinishedProductSpecification $specification): array
    {
        $resolved = $specification instanceof FinishedProductSpecification
            ? $specification
            : FinishedProductSpecification::query()->findOrFail($specification);

        $computed = FinishedProductComputedPrice::query()
            ->where('finished_product_specification_id', $resolved->id)
            ->first();

        $profile = FinishedProductAggregationProfile::query()
            ->where('finished_product_specification_id', $resolved->id)
            ->first();

        $sources = FinishedProductPriceSource::query()
            ->with(['supplier:id,name'])
            ->withCount('evidenceAssets')
            ->where('finished_product_specification_id', $resolved->id)
            ->orderByRaw("
                CASE status
                    WHEN 'active' THEN 0
                    WHEN 'stale' THEN 1
                    WHEN 'inactive' THEN 2
                    WHEN 'invalid' THEN 3
                    WHEN 'superseded' THEN 4
                    ELSE 5
                END
            ")
            ->orderByDesc('effective_date')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();

        $hasComputedPrice = $computed !== null && $computed->computed_price_per_m2 !== null;
        $hasSources = $sources->isNotEmpty();
        $hasProfile = $profile !== null;

        return [
            'summary' => [
                'computed_price_per_m2' => $hasComputedPrice ? (float) $computed->computed_price_per_m2 : null,
                'method' => $computed?->method ?? $profile?->method,
                'source_count' => $hasComputedPrice ? (int) $computed->source_count : $sources->count(),
                'min_price' => $computed?->min_price !== null ? (float) $computed->min_price : null,
                'max_price' => $computed?->max_price !== null ? (float) $computed->max_price : null,
                'computed_at' => $computed?->computed_at?->toIso8601String(),
                'status' => $this->resolveStatus($hasComputedPrice, $hasSources, $hasProfile),
            ],
            'profile' => [
                'method' => $profile?->method,
                'include_only_active' => $profile?->include_only_active ?? true,
                'exclude_stale' => $profile?->exclude_stale ?? true,
                'minimum_sources_count' => $profile?->minimum_sources_count,
            ],
            'sources' => $sources->map(function (FinishedProductPriceSource $source): array {
                return [
                    'id' => $source->id,
                    'supplier' => [
                        'id' => $source->supplier?->id,
                        'name' => $source->supplier?->name,
                    ],
                    'source_kind' => $source->source_kind,
                    'source_price' => $source->source_price !== null ? (float) $source->source_price : null,
                    'source_unit' => $source->source_unit,
                    'conversion_factor_to_m2' => $source->conversion_factor_to_m2 !== null
                        ? (float) $source->conversion_factor_to_m2
                        : null,
                    'price_per_m2_normalized' => $source->price_per_m2_normalized !== null
                        ? (float) $source->price_per_m2_normalized
                        : null,
                    'captured_at' => $source->captured_at?->toIso8601String(),
                    'effective_date' => $source->effective_date?->toDateString(),
                    'status' => $source->status,
                    'stale_reason' => $source->stale_reason,
                    'article' => $source->article,
                    'category' => $source->category,
                    'description' => $source->description,
                    'notes' => $source->notes,
                    'evidence_assets_count' => (int) $source->evidence_assets_count,
                    'has_evidence' => (int) $source->evidence_assets_count > 0,
                ];
            })->values()->all(),
        ];
    }

    private function resolveStatus(bool $hasComputedPrice, bool $hasSources, bool $hasProfile): string
    {
        if ($hasComputedPrice) {
            return 'computed';
        }

        if ($hasSources) {
            return 'sources_only';
        }

        if ($hasProfile) {
            return 'profile_only';
        }

        return 'none';
    }
}
