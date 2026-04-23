<?php

namespace App\Services;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\FinishedProductPriceEvidenceAsset;
use App\Models\GenericEvidenceAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FinishedProductEvidenceRecordBridge
{
    public function __construct(
        private UrlNormalizer $urlNormalizer,
    ) {}

    public function materializeForSpecification(int $specificationId, int $userId): int
    {
        if ($specificationId <= 0 || $userId <= 0) {
            return 0;
        }

        $assets = FinishedProductPriceEvidenceAsset::query()
            ->whereHas('source.specification', function ($query) use ($specificationId, $userId) {
                $query->where('id', $specificationId)
                    ->where('user_id', $userId);
            })
            ->with(['source.supplier', 'source.specification'])
            ->orderBy('id')
            ->get();

        $createdOrUpdated = 0;

        foreach ($assets as $asset) {
            $record = $this->materializeAsset($asset, $userId, $specificationId);
            if ($record) {
                $createdOrUpdated++;
            }
        }

        return $createdOrUpdated;
    }

    public function recordBelongsToSpecification(EvidenceRecord $record, int $specificationId, int $userId): bool
    {
        if ($record->created_by !== $userId || $record->cost_component !== CostComponent::FACADE) {
            return false;
        }

        return EvidenceLink::query()
            ->where('evidence_record_id', $record->id)
            ->where('linkable_type', 'finished_product_price_evidence_asset')
            ->whereExists(function ($query) use ($specificationId, $userId) {
                $query->select(DB::raw(1))
                    ->from('finished_product_price_evidence_assets as fpea')
                    ->join('finished_product_price_sources as fpps', 'fpps.id', '=', 'fpea.finished_product_price_source_id')
                    ->join('finished_product_specifications as fps', 'fps.id', '=', 'fpps.finished_product_specification_id')
                    ->whereColumn('fpea.id', 'evidence_links.linkable_id')
                    ->where('fps.id', $specificationId)
                    ->where('fps.user_id', $userId);
            })
            ->exists();
    }

    private function materializeAsset(
        FinishedProductPriceEvidenceAsset $asset,
        int $userId,
        int $specificationId,
    ): ?EvidenceRecord {
        $source = $asset->source;
        if (!$source || !$source->specification || (int) $source->specification->user_id !== $userId) {
            return null;
        }

        $existingLink = EvidenceLink::query()
            ->where('linkable_type', 'finished_product_price_evidence_asset')
            ->where('linkable_id', $asset->id)
            ->with('evidenceRecord')
            ->first();

        $record = $existingLink?->evidenceRecord;

        $sourceUrl = $asset->source_url ? $this->urlNormalizer->normalize($asset->source_url) : null;
        $assetType = (string) ($asset->asset_type ?: FinishedProductPriceEvidenceAsset::TYPE_FILE);
        $metadata = [
            'origin' => 'finished_product_price_evidence_asset',
            'finished_product_specification_id' => $specificationId,
            'finished_product_price_source_id' => $source->id,
            'finished_product_price_evidence_asset_id' => $asset->id,
            'finished_product_evidence_asset_type' => $assetType,
        ];

        $payload = [
            'cost_component' => CostComponent::FACADE,
            'source_type' => $assetType === FinishedProductPriceEvidenceAsset::TYPE_LINK
                ? SourceType::SUPPLIER_WEBSITE
                : SourceType::DOCUMENT,
            'capture_method' => $assetType === FinishedProductPriceEvidenceAsset::TYPE_LINK
                ? CaptureMethod::MANUAL_ENTRY
                : CaptureMethod::FILE_UPLOAD,
            'verification_status' => VerificationStatus::PENDING,
            'source_url' => $sourceUrl,
            'source_domain' => $sourceUrl ? (parse_url($sourceUrl, PHP_URL_HOST) ?: null) : null,
            'observed_price' => $source->price_per_m2_normalized ?? $source->source_price,
            'currency' => 'RUB',
            'observed_at' => $asset->captured_at ?? $source->captured_at ?? now(),
            'extracted_name' => $source->description
                ?: $source->article
                ?: $source->specification?->name
                ?: 'Доказательство фасада',
            'extracted_article' => $source->article,
            'metadata_json' => array_replace($record?->metadata_json ?? [], $metadata),
            'created_by' => $userId,
        ];

        if (!$record) {
            $record = EvidenceRecord::create([
                'uuid' => (string) Str::uuid(),
                ...$payload,
            ]);

            EvidenceLink::create([
                'evidence_record_id' => $record->id,
                'linkable_type' => 'finished_product_price_evidence_asset',
                'linkable_id' => $asset->id,
                'relation_type' => 'finished_product_facade_evidence',
            ]);
        } else {
            $record->update($payload);
        }

        if ($asset->file_path && !GenericEvidenceAsset::where('evidence_record_id', $record->id)->exists()) {
            $genericFilePath = $this->copyAssetFileForEvidenceRecord($asset, $record);

            GenericEvidenceAsset::create([
                'uuid' => (string) Str::uuid(),
                'evidence_record_id' => $record->id,
                'asset_type' => $this->toGenericAssetType($assetType),
                'file_path' => $genericFilePath,
                'original_filename' => $asset->original_name,
                'mime_type' => $asset->mime_type,
                'file_size' => $asset->file_size,
                'sha256' => $asset->content_hash,
                'metadata_json' => $metadata,
                'uploaded_by' => $userId,
            ]);
        }

        return $record;
    }

    private function toGenericAssetType(string $assetType): string
    {
        return match ($assetType) {
            FinishedProductPriceEvidenceAsset::TYPE_SCREENSHOT,
            FinishedProductPriceEvidenceAsset::TYPE_IMAGE => 'screenshot',
            default => 'document',
        };
    }

    private function copyAssetFileForEvidenceRecord(
        FinishedProductPriceEvidenceAsset $asset,
        EvidenceRecord $record,
    ): string {
        $sourcePath = (string) $asset->file_path;
        $disk = Storage::disk('public');

        if (!$disk->exists($sourcePath)) {
            return $sourcePath;
        }

        $extension = pathinfo($asset->original_name ?: $sourcePath, PATHINFO_EXTENSION);
        $targetPath = 'evidence-records/' . $record->uuid . '/' . Str::uuid()->toString() . ($extension ? '.' . $extension : '');

        $disk->put($targetPath, $disk->get($sourcePath));

        return $targetPath;
    }
}
