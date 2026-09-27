<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidenceAsset;
use App\Services\Storage\ObjectStorage;

class EvidenceAssetController extends Controller
{
    public function file(int $assetId, ObjectStorage $storage)
    {
        $asset = EvidenceAsset::with('evidenceArtifact.revisionRun.project')
            ->findOrFail($assetId);

        $project = $asset->evidenceArtifact?->revisionRun?->project;

        if (!$project || $project->user_id !== auth()->id()) {
            abort(403, 'Access denied.');
        }

        return $storage->downloadResponse(
            $asset->storage_disk ?: 'public',
            $asset->file_path,
            $asset->original_filename ?: basename($asset->file_path),
            $asset->mime_type ?: 'application/octet-stream',
            str_starts_with((string) $asset->mime_type, 'image/'),
        );
    }
}
