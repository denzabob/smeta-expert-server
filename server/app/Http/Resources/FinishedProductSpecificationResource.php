<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinishedProductSpecificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $computedPrice = $this->whenLoaded('computedPrice');
        $aggregationProfile = $this->whenLoaded('aggregationProfile');

        return [
            'id' => $this->id,
            'product_type' => $this->product_type,
            'name' => $this->name,
            'article' => $this->article,
            'is_active' => (bool) $this->is_active,
            'facade_class' => $this->facade_class,
            'base_type' => $this->base_type,
            'thickness_mm' => $this->thickness_mm,
            'covering' => $this->covering,
            'cover_type' => $this->cover_type,
            'collection' => $this->collection,
            'decor_label' => $this->decor_label,
            'price_group_label' => $this->price_group_label,
            'notes' => $this->notes,
            'metadata' => $this->metadata ?? [],
            'source_count' => isset($this->source_count)
                ? (int) $this->source_count
                : ($this->relationLoaded('priceSources') ? $this->priceSources->count() : 0),
            'aggregation_method' => $aggregationProfile?->method,
            'computed_price_summary' => [
                'computed_price_per_m2' => $computedPrice?->computed_price_per_m2 !== null
                    ? (float) $computedPrice->computed_price_per_m2
                    : null,
                'method' => $computedPrice?->method ?? $aggregationProfile?->method,
                'source_count' => $computedPrice?->source_count !== null
                    ? (int) $computedPrice->source_count
                    : (isset($this->source_count) ? (int) $this->source_count : 0),
                'min_price' => $computedPrice?->min_price !== null ? (float) $computedPrice->min_price : null,
                'max_price' => $computedPrice?->max_price !== null ? (float) $computedPrice->max_price : null,
                'computed_at' => $computedPrice?->computed_at?->toIso8601String(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
