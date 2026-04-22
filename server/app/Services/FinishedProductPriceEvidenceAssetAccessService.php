<?php

namespace App\Services;

use App\Models\FinishedProductPriceEvidenceAsset;
use Illuminate\Support\Facades\Storage;

class FinishedProductPriceEvidenceAssetAccessService
{
    public function describe(FinishedProductPriceEvidenceAsset $asset): array
    {
        $filePath = $asset->file_path ? trim((string) $asset->file_path) : null;
        $sourceUrl = $asset->source_url ? trim((string) $asset->source_url) : null;
        $mimeType = $asset->mime_type ? trim((string) $asset->mime_type) : null;

        if ($sourceUrl) {
            return [
                'can_preview' => false,
                'can_download' => false,
                'preview_url' => null,
                'download_url' => null,
                'open_url' => $sourceUrl,
                'access_kind' => 'external',
            ];
        }

        if (!$filePath) {
            return $this->noAccess();
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($filePath)) {
            return $this->noAccess();
        }

        $canPreview = $this->canPreviewInline($asset->asset_type, $mimeType, $filePath);
        $baseUrl = url("/api/finished-product-price-evidence-assets/{$asset->id}/open");

        return [
            'can_preview' => $canPreview,
            'can_download' => true,
            'preview_url' => $canPreview ? $baseUrl : null,
            'download_url' => $baseUrl . '?download=1',
            'open_url' => $canPreview ? $baseUrl : ($baseUrl . '?download=1'),
            'access_kind' => $canPreview ? 'preview' : 'download',
        ];
    }

    public function noAccess(): array
    {
        return [
            'can_preview' => false,
            'can_download' => false,
            'preview_url' => null,
            'download_url' => null,
            'open_url' => null,
            'access_kind' => 'none',
        ];
    }

    private function canPreviewInline(?string $assetType, ?string $mimeType, string $filePath): bool
    {
        if ($mimeType !== null) {
            if (str_starts_with($mimeType, 'image/')) {
                return true;
            }

            if ($mimeType === 'application/pdf') {
                return true;
            }
        }

        if (in_array($assetType, ['screenshot', 'image'], true)) {
            return true;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'pdf'], true);
    }
}
