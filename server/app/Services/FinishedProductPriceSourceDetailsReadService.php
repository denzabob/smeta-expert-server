<?php

namespace App\Services;

use App\Models\FinishedProductPriceEvidenceAsset;
use App\Models\FinishedProductPriceSource;

class FinishedProductPriceSourceDetailsReadService
{
    public function __construct(
        private FinishedProductPriceEvidenceAssetAccessService $assetAccessService,
    ) {}

    public function forSource(FinishedProductPriceSource $source): array
    {
        $source->loadMissing(['supplier:id,name', 'specification:id,user_id,product_type']);

        $evidenceAssets = $source->evidenceAssets()
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();

        return [
            'source' => [
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
                'metadata' => $source->metadata ?? [],
            ],
            'evidence_assets' => $evidenceAssets->map(function (FinishedProductPriceEvidenceAsset $asset): array {
                $access = $this->assetAccessService->describe($asset);

                return [
                    'id' => $asset->id,
                    'asset_type' => $asset->asset_type,
                    'file_path' => $asset->file_path,
                    'original_name' => $asset->original_name,
                    'mime_type' => $asset->mime_type,
                    'file_size' => $asset->file_size,
                    'source_url' => $asset->source_url,
                    'content_hash' => $asset->content_hash,
                    'captured_at' => $asset->captured_at?->toIso8601String(),
                    'metadata' => $asset->metadata ?? [],
                    'can_preview' => $access['can_preview'],
                    'can_download' => $access['can_download'],
                    'preview_url' => $access['preview_url'],
                    'download_url' => $access['download_url'],
                    'open_url' => $access['open_url'],
                    'access_kind' => $access['access_kind'],
                ];
            })->values()->all(),
        ];
    }
}
