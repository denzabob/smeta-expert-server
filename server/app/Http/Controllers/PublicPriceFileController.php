<?php

namespace App\Http\Controllers;

use App\Models\PriceListVersion;
use App\Models\ProjectPositionPriceQuote;
use App\Models\RevisionPublication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Secure download endpoint for price list files.
 *
 * Access is public (no auth), but protected by document_token:
 * - The price_list_version must be referenced in quotes of the published document
 * - No internal file paths are exposed in HTML
 */
class PublicPriceFileController extends Controller
{
    /**
     * GET /public/price-file/{versionId}/{documentToken}
     *
     * @param int $versionId  — PriceListVersion ID
     * @param string $documentToken — RevisionPublication public_id (acts as document token)
     */
    public function download(int $versionId, string $documentToken)
    {
        // 1. Find the publication by document_token (= public_id)
        $publication = RevisionPublication::where('public_id', $documentToken)
            ->where('is_active', true)
            ->first();

        if (!$publication) {
            abort(403, 'Invalid or expired document token.');
        }

        // Check expiry
        if ($publication->expires_at && $publication->expires_at->isPast()) {
            abort(403, 'Document link has expired.');
        }

        // 2. Verify that the price_list_version is used in this document's project quotes
        $revision = $publication->revision;
        if (!$revision) {
            abort(403, 'Associated revision not found.');
        }

        $project = $revision->project;
        if (!$project) {
            abort(403, 'Associated project not found.');
        }

        // Check if this version is referenced in any position quote of the project
        $isUsed = ProjectPositionPriceQuote::where('price_list_version_id', $versionId)
            ->whereHas('position', function ($q) use ($project) {
                $q->where('project_id', $project->id);
            })
            ->exists();

        if (!$isUsed) {
            // Also check project_price_list_versions as fallback
            $isLinked = $project->priceListVersions()
                ->where('price_list_versions.id', $versionId)
                ->exists();

            if (!$isLinked) {
                abort(403, 'This price file is not associated with the requested document.');
            }
        }

        // 3. Fetch the version and serve the file
        $version = PriceListVersion::find($versionId);
        if (!$version) {
            abort(404, 'Price list version not found.');
        }

        if (!$version->file_path || !$version->storage_disk) {
            abort(404, 'No file attached to this price list version.');
        }

        $disk = Storage::disk($version->storage_disk);
        if (!$disk->exists($version->file_path)) {
            abort(404, 'File not found on storage.');
        }

        $filename = $version->original_filename ?: basename($version->file_path);

        return $disk->download($version->file_path, $filename, [
            'Content-Type' => $this->guessContentType($filename),
            'Cache-Control' => 'no-store, must-revalidate',
        ]);
    }

    /**
     * Guess content type from filename extension.
     */
    private function guessContentType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'pdf' => 'application/pdf',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            default => 'application/octet-stream',
        };
    }
}
