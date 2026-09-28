<?php

namespace App\Services;

use App\Models\GenericEvidenceAsset;
use App\Models\LaborEvidenceSource;
use App\Services\Storage\ObjectStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaborEvidenceAssetService
{
    public function __construct(private readonly ObjectStorage $storage) {}

    public function store(LaborEvidenceSource $source, UploadedFile $file, string $assetType, int $uploadedBy,
        ?\App\Services\Storage\PreparedStorageUpload $prepared = null): GenericEvidenceAsset
    {
        $record = $source->evidenceRecord;
        if (!$record) {
            throw new \RuntimeException('Labor evidence source has no evidence record.');
        }
        $create = function (string $path) use ($record, $file, $assetType, $uploadedBy) {
            return GenericEvidenceAsset::create([
                'uuid' => (string) Str::uuid(),
                'evidence_record_id' => $record->id,
                'asset_type' => $assetType,
                'file_path' => $path,
                'storage_disk' => ObjectStorage::DISK,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $uploadedBy,
            ]);
        };
        if ($prepared !== null) {
            return $create($prepared->path());
        }
        return app(\App\Services\Storage\AccountFileStorage::class)->upload(
            $assetType === 'screenshot' ? 'screenshots/chrome/generic' : 'evidence-records/' . $record->uuid,
            $file, $uploadedBy, $create);
    }

    public function delete(GenericEvidenceAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $asset->delete();
        });
    }

}
