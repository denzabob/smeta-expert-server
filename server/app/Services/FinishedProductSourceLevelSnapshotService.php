<?php

namespace App\Services;

use App\Models\FinishedProductPriceEvidenceAsset;
use App\Models\FinishedProductPriceSource;
use App\Models\FinishedProductSpecification;

class FinishedProductSourceLevelSnapshotService
{
    public function __construct(
        private FinishedProductPriceAggregationService $aggregationService,
        private FinishedProductPriceEvidenceAssetAccessService $assetAccessService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function captureForSpecification(FinishedProductSpecification $specification, array $summary = []): array
    {
        $aggregation = $this->aggregationService->aggregateForSpecification($specification);
        $usedSourceIds = collect($aggregation['used_source_ids'] ?? [])
            ->filter(fn ($id) => $id !== null)
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $sources = empty($usedSourceIds)
            ? collect()
            : FinishedProductPriceSource::query()
                ->with(['supplier:id,name', 'evidenceAssets'])
                ->whereIn('id', $usedSourceIds)
                ->orderByDesc('effective_date')
                ->orderByDesc('captured_at')
                ->orderByDesc('id')
                ->get()
                ->sortBy(fn (FinishedProductPriceSource $source) => array_search($source->id, $usedSourceIds, true))
                ->values();

        $aggregationSummary = [
            'method' => $summary['method'] ?? $aggregation['method'] ?? null,
            'computed_price_per_m2' => $summary['computed_price_per_m2'] ?? $aggregation['computed_price_per_m2'] ?? null,
            'source_count' => $summary['source_count'] ?? $aggregation['source_count'] ?? 0,
            'min_price' => $summary['min_price'] ?? $aggregation['min_price'] ?? null,
            'max_price' => $summary['max_price'] ?? $aggregation['max_price'] ?? null,
        ];

        return [
            'contract_version' => 1,
            'captured_at' => now()->toIso8601String(),
            'materialization_status' => $sources->isNotEmpty() ? 'captured' : 'summary_only',
            'aggregation' => $aggregationSummary,
            'included_source_count' => $sources->count(),
            'sources' => $sources->map(function (FinishedProductPriceSource $source): array {
                return [
                    'source_ref' => [
                        'id' => $source->id,
                        'price_list_version_id' => $source->price_list_version_id,
                    ],
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
                    'metadata' => $source->metadata ?? [],
                    'evidence_assets_count' => $source->evidenceAssets->count(),
                    'evidence_assets' => $source->evidenceAssets
                        ->sortByDesc(fn (FinishedProductPriceEvidenceAsset $asset) => optional($asset->captured_at)?->timestamp ?? 0)
                        ->values()
                        ->map(function (FinishedProductPriceEvidenceAsset $asset): array {
                            $access = $this->assetAccessService->describe($asset);

                            return [
                                'asset_ref' => ['id' => $asset->id],
                                'asset_type' => $asset->asset_type,
                                'display_label' => $asset->original_name
                                    ?: $asset->source_url
                                    ?: ('Asset #' . $asset->id),
                                'original_name' => $asset->original_name,
                                'mime_type' => $asset->mime_type,
                                'file_size' => $asset->file_size,
                                'source_url' => $asset->source_url,
                                'file_path' => $asset->file_path,
                                'storage_reference' => $asset->file_path
                                    ? ['disk' => 'public', 'path' => $asset->file_path]
                                    : null,
                                'content_hash' => $asset->content_hash,
                                'captured_at' => $asset->captured_at?->toIso8601String(),
                                'metadata' => $asset->metadata ?? [],
                                'access_kind' => $access['access_kind'],
                            ];
                        })
                        ->all(),
                ];
            })->all(),
        ];
    }
}
