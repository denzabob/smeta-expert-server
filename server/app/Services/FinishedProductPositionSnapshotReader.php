<?php

namespace App\Services;

use App\Models\Material;
use App\Models\ProjectPosition;

class FinishedProductPositionSnapshotReader
{
    public function supports(ProjectPosition $position): bool
    {
        return $position->kind === ProjectPosition::KIND_FACADE
            && !empty($position->finished_product_specification_id)
            && is_array($position->finished_product_pricing_snapshot)
            && !empty(data_get($position->finished_product_pricing_snapshot, 'specification.id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function read(ProjectPosition $position): array
    {
        $snapshot = is_array($position->finished_product_pricing_snapshot)
            ? $position->finished_product_pricing_snapshot
            : [];

        $specification = (array) data_get($snapshot, 'specification', []);
        $pricing = (array) data_get($snapshot, 'pricing', []);

        $specificationId = (int) ($specification['id'] ?? $position->finished_product_specification_id ?? 0);
        $baseType = $specification['base_type'] ?? $position->finishedProductSpecification?->base_type;
        $covering = $specification['covering'] ?? $position->finishedProductSpecification?->covering;
        $coverType = $specification['cover_type'] ?? $position->finishedProductSpecification?->cover_type;
        $collection = $specification['collection'] ?? $position->finishedProductSpecification?->collection;

        $finishType = $position->finish_type;
        if (!$finishType && is_string($covering) && in_array($covering, ProjectPosition::FINISH_TYPES, true)) {
            $finishType = $covering;
        }

        $finishTypeLabel = $finishType
            ? (Material::FINISH_LABELS[$finishType] ?? $finishType)
            : ($covering ? (Material::FINISH_LABELS[$covering] ?? $covering) : null);

        $finishName = $position->finish_name
            ?: ($coverType ?: $covering ?: $collection);

        $baseMaterialLabel = $position->base_material_label;
        if (!$baseMaterialLabel && is_string($baseType) && $baseType !== '') {
            $baseMaterialLabel = mb_strtoupper($baseType);
        }

        return [
            'reference_type' => 'finished_product_specification',
            'reference_id' => $specificationId,
            'group_key' => 'finished_product_specification:' . $specificationId,
            'name' => $specification['name']
                ?? $position->finishedProductSpecification?->name
                ?? $position->decor_label
                ?? 'Фасад',
            'article' => $specification['article'] ?? $position->finishedProductSpecification?->article,
            'facade_class' => $specification['facade_class'] ?? $position->finishedProductSpecification?->facade_class,
            'base_type' => $baseType,
            'base_material_label' => $baseMaterialLabel,
            'thickness_mm' => $specification['thickness_mm'] ?? $position->thickness_mm,
            'covering' => $covering,
            'cover_type' => $coverType,
            'collection' => $collection,
            'decor_label' => $specification['decor_label'] ?? $position->decor_label,
            'price_group_label' => $specification['price_group_label'] ?? $position->finishedProductSpecification?->price_group_label,
            'finish_type' => $finishType,
            'finish_type_label' => $finishTypeLabel,
            'finish_name' => $finishName,
            'price_per_m2' => isset($pricing['computed_price_per_m2'])
                ? (float) $pricing['computed_price_per_m2']
                : (float) ($position->price_per_m2 ?? 0),
            'price_method' => $pricing['aggregation_method'] ?? $position->price_method ?? 'single',
            'price_sources_count' => isset($pricing['source_count'])
                ? (int) $pricing['source_count']
                : ($position->price_sources_count !== null ? (int) $position->price_sources_count : null),
            'price_min' => isset($pricing['min_price']) ? (float) $pricing['min_price'] : ($position->price_min !== null ? (float) $position->price_min : null),
            'price_max' => isset($pricing['max_price']) ? (float) $pricing['max_price'] : ($position->price_max !== null ? (float) $position->price_max : null),
            'computed_at' => $pricing['computed_at'] ?? null,
            'captured_at' => $snapshot['captured_at'] ?? null,
            'product_type' => $snapshot['product_type'] ?? null,
            'source_level_snapshot' => is_array($snapshot['source_level_snapshot'] ?? null)
                ? $snapshot['source_level_snapshot']
                : null,
        ];
    }

}
