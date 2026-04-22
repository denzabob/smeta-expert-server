<?php

namespace App\Services;

use App\Models\FinishedProductAggregationProfile;
use App\Models\FinishedProductComputedPrice;
use App\Models\FinishedProductPriceSource;
use App\Models\Material;
use Illuminate\Support\Collection;

class FinishedProductComputedPriceReadBridge
{
    public function __construct(
        private FinishedProductPricingBridge $pricingBridge,
    ) {}

    public function forMaterial(int|Material $material): array
    {
        $resolved = $this->pricingBridge->resolveFacadeMaterial($material);

        $computed = FinishedProductComputedPrice::query()
            ->where('finished_product_material_id', $resolved->id)
            ->first();

        $profile = FinishedProductAggregationProfile::query()
            ->where('finished_product_material_id', $resolved->id)
            ->first();

        $sourceCount = FinishedProductPriceSource::query()
            ->where('finished_product_material_id', $resolved->id)
            ->count();

        return $this->buildPayload($computed, $profile, $sourceCount);
    }

    public function attachToMaterial(Material $material): Material
    {
        $material->setAttribute('finished_product_pricing_summary', $this->forMaterial($material));

        return $material;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Material> $materials
     */
    public function attachToCollection(Collection $materials): Collection
    {
        $facades = $materials
            ->filter(fn ($material) => $material instanceof Material && $material->type === Material::TYPE_FACADE)
            ->values();

        if ($facades->isEmpty()) {
            return $materials;
        }

        $ids = $facades->pluck('id')->all();

        $computedByMaterial = FinishedProductComputedPrice::query()
            ->whereIn('finished_product_material_id', $ids)
            ->get()
            ->keyBy('finished_product_material_id');

        $profileByMaterial = FinishedProductAggregationProfile::query()
            ->whereIn('finished_product_material_id', $ids)
            ->get()
            ->keyBy('finished_product_material_id');

        $sourceCounts = FinishedProductPriceSource::query()
            ->selectRaw('finished_product_material_id, COUNT(*) as aggregate_count')
            ->whereIn('finished_product_material_id', $ids)
            ->groupBy('finished_product_material_id')
            ->pluck('aggregate_count', 'finished_product_material_id');

        foreach ($facades as $material) {
            $material->setAttribute('finished_product_pricing_summary', $this->buildPayload(
                $computedByMaterial->get($material->id),
                $profileByMaterial->get($material->id),
                (int) ($sourceCounts[$material->id] ?? 0),
            ));
        }

        return $materials;
    }

    private function buildPayload(
        ?FinishedProductComputedPrice $computed,
        ?FinishedProductAggregationProfile $profile,
        int $sourceCount,
    ): array {
        $hasComputedPrice = $computed !== null && $computed->computed_price_per_m2 !== null;
        $hasSources = $sourceCount > 0;
        $hasProfile = $profile !== null;

        return [
            'available' => $hasComputedPrice || $hasSources || $hasProfile,
            'computed_price_per_m2' => $hasComputedPrice ? (float) $computed->computed_price_per_m2 : null,
            'method' => $computed?->method ?? $profile?->method,
            'source_count' => $hasComputedPrice ? (int) $computed->source_count : $sourceCount,
            'min_price' => $computed?->min_price !== null ? (float) $computed->min_price : null,
            'max_price' => $computed?->max_price !== null ? (float) $computed->max_price : null,
            'computed_at' => $computed?->computed_at?->toIso8601String(),
            'profile' => [
                'method' => $profile?->method,
                'include_only_active' => $profile?->include_only_active ?? true,
                'exclude_stale' => $profile?->exclude_stale ?? true,
                'minimum_sources_count' => $profile?->minimum_sources_count,
            ],
            'has_new_pricing_sources' => $hasSources,
            'has_computed_price' => $hasComputedPrice,
            'new_pricing_status' => $this->resolveStatus($hasComputedPrice, $hasSources, $hasProfile),
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
