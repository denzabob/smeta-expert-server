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

    public function store(LaborEvidenceSource $source, UploadedFile $file, string $assetType, int $uploadedBy): GenericEvidenceAsset
    {
        return DB::transaction(function () use ($source, $file, $assetType, $uploadedBy) {
            $record = $source->evidenceRecord;

            if (!$record) {
                throw new \RuntimeException('Labor evidence source has no evidence record.');
            }

            $path = $this->storage->storeUploaded(
                $assetType === 'screenshot' ? 'screenshots/chrome/generic' : 'evidence-records/' . $record->uuid,
                $file,
                $uploadedBy,
            );

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
        });
    }

    public function delete(GenericEvidenceAsset $asset): void
    {
        DB::transaction(function () use ($asset) {
            if ($asset->file_path) {
                $this->storage->delete($asset->storage_disk ?: 'public', $asset->file_path);
            }

            $asset->delete();
        });
    }

}
