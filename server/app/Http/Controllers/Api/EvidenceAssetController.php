<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidenceAsset;
use Illuminate\Support\Facades\Storage;

class EvidenceAssetController extends Controller
{
    public function file(int $assetId)
    {
        $asset = EvidenceAsset::with('evidenceArtifact.revisionRun.project')
            ->findOrFail($assetId);

        $project = $asset->evidenceArtifact?->revisionRun?->project;

        if (!$project || $project->user_id !== auth()->id()) {
            abort(403, 'Access denied.');
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($asset->file_path)) {
            abort(404, 'File not found.');
        }

        return $disk->response($asset->file_path, $asset->original_filename, [
            'Content-Type' => $asset->mime_type ?? 'application/octet-stream',
        ]);
    }
}
