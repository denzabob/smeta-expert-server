<?php

namespace App\Services;

use App\Models\GenericEvidenceAsset;
use App\Models\LaborEvidenceSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LaborEvidenceAssetService
{
    public function store(LaborEvidenceSource $source, UploadedFile $file, string $assetType, int $uploadedBy): GenericEvidenceAsset
    {
        return DB::transaction(function () use ($source, $file, $assetType, $uploadedBy) {
            $record = $source->evidenceRecord;

            if (!$record) {
                throw new \RuntimeException('Labor evidence source has no evidence record.');
            }

            $path = $this->storeFile($record->uuid, $file, $assetType);

            return GenericEvidenceAsset::create([
                'uuid' => (string) Str::uuid(),
                'evidence_record_id' => $record->id,
                'asset_type' => $assetType,
                'file_path' => $path,
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
            if ($asset->file_path && Storage::disk('public')->exists($asset->file_path)) {
                Storage::disk('public')->delete($asset->file_path);
            }

            $asset->delete();
        });
    }

    private function storeFile(string $recordUuid, UploadedFile $file, string $assetType): string
    {
        if ($assetType === 'screenshot') {
            return $file->store('screenshots/chrome/generic/' . now()->format('Y/m'), 'public');
        }

        return $file->store('evidence-records/' . $recordUuid, 'public');
    }
}
