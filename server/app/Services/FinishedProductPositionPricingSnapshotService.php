<?php

namespace App\Services;

use App\Models\FinishedProductSpecification;
use App\Models\ProjectPosition;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class FinishedProductPositionPricingSnapshotService
{
    public function __construct(
        private FinishedProductSpecificationAccessService $accessService,
    ) {}

    /**
     * @return array{specification: FinishedProductSpecification, snapshot: array<string,mixed>}
     */
    public function captureForUser(int $userId, int|FinishedProductSpecification $specification): array
    {
        $resolved = $this->accessService->resolveOwnedFacadeSpecification($userId, $specification);
        $resolved->loadMissing(['computedPrice', 'aggregationProfile']);

        $computed = $resolved->computedPrice;
        $computedPrice = $computed ? (float) $computed->computed_price_per_m2 : null;

        if ($computedPrice === null || $computedPrice <= 0) {
            throw ValidationException::withMessages([
                'finished_product_specification_id' => 'Для выбранной спецификации фасада не рассчитана цена за м².',
            ]);
        }

        $snapshot = [
            'contract_version' => 1,
            'product_type' => $resolved->product_type,
            'captured_at' => now()->toIso8601String(),
            'specification' => [
                'id' => (int) $resolved->id,
                'name' => $resolved->name,
                'article' => $resolved->article,
                'facade_class' => $resolved->facade_class,
                'base_type' => $resolved->base_type,
                'thickness_mm' => $resolved->thickness_mm,
                'covering' => $resolved->covering,
                'cover_type' => $resolved->cover_type,
                'collection' => $resolved->collection,
                'decor_label' => $resolved->decor_label,
                'price_group_label' => $resolved->price_group_label,
            ],
            'pricing' => [
                'computed_price_per_m2' => $computedPrice,
                'aggregation_method' => $computed->method ?? $resolved->aggregationProfile?->method,
                'source_count' => $computed ? (int) $computed->source_count : 0,
                'min_price' => $computed?->min_price !== null ? (float) $computed->min_price : null,
                'max_price' => $computed?->max_price !== null ? (float) $computed->max_price : null,
                'computed_at' => $computed?->computed_at?->toIso8601String(),
            ],
            'aggregation_profile' => [
                'method' => $resolved->aggregationProfile?->method,
                'include_only_active' => $resolved->aggregationProfile?->include_only_active ?? true,
                'exclude_stale' => $resolved->aggregationProfile?->exclude_stale ?? true,
                'minimum_sources_count' => $resolved->aggregationProfile?->minimum_sources_count,
            ],
        ];

        return [
            'specification' => $resolved,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function applySnapshot(array $attributes, FinishedProductSpecification $specification, array $snapshot): array
    {
        $pricing = Arr::get($snapshot, 'pricing', []);
        $computedPrice = Arr::get($pricing, 'computed_price_per_m2');

        $finishType = in_array($specification->covering, ProjectPosition::FINISH_TYPES, true)
            ? $specification->covering
            : null;

        $finishName = $specification->cover_type
            ?: $specification->covering
            ?: $specification->collection;

        $attributes['finished_product_specification_id'] = $specification->id;
        $attributes['finished_product_pricing_snapshot'] = $snapshot;
        $attributes['facade_material_id'] = null;
        $attributes['material_price_id'] = null;
        $attributes['base_material_label'] = $specification->base_type
            ? mb_strtoupper($specification->base_type)
            : null;
        $attributes['thickness_mm'] = $specification->thickness_mm;
        $attributes['finish_type'] = $finishType;
        $attributes['finish_name'] = $finishName;
        $attributes['decor_label'] = $specification->decor_label ?: $finishName;
        $attributes['price_per_m2'] = $computedPrice;
        $attributes['price_method'] = Arr::get($pricing, 'aggregation_method');
        $attributes['price_sources_count'] = Arr::get($pricing, 'source_count');
        $attributes['price_min'] = Arr::get($pricing, 'min_price');
        $attributes['price_max'] = Arr::get($pricing, 'max_price');
        $attributes['edge_material_id'] = null;
        $attributes['edge_scheme'] = 'none';

        return $attributes;
    }
}
