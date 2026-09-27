<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenericEvidenceAsset;
use App\Services\Storage\ObjectStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GenericEvidenceAssetController extends Controller
{
    public function file(Request $request, int $assetId, ObjectStorage $storage)
    {
        $asset = GenericEvidenceAsset::with('evidenceRecord')->findOrFail($assetId);
        $userId = (int) $request->user()->id;
        $record = $asset->evidenceRecord;

        $ownedLaborSource = DB::table('labor_evidence_sources')
            ->where('evidence_record_id', $asset->evidence_record_id)
            ->where('user_id', $userId)
            ->exists();

        $ownedFinishedProductSource = DB::table('evidence_links as el')
            ->join('finished_product_price_evidence_assets as fpea', function ($join) {
                $join->on('fpea.id', '=', 'el.linkable_id')
                    ->where('el.linkable_type', '=', 'finished_product_price_evidence_asset');
            })
            ->join('finished_product_price_sources as fpps', 'fpps.id', '=', 'fpea.finished_product_price_source_id')
            ->join('finished_product_specifications as fps', 'fps.id', '=', 'fpps.finished_product_specification_id')
            ->where('el.evidence_record_id', $asset->evidence_record_id)
            ->where('fps.user_id', $userId)
            ->exists();

        $ownedSupplierEvidence = DB::table('evidence_links as el')
            ->where('el.evidence_record_id', $asset->evidence_record_id)
            ->where(function ($query) use ($userId) {
                $query->where(function ($query) use ($userId) {
                    $query->where('el.linkable_type', 'price_list_version')
                        ->whereExists(function ($subquery) use ($userId) {
                            $subquery->selectRaw('1')
                                ->from('price_list_versions as plv')
                                ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                                ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                                ->whereColumn('plv.id', 'el.linkable_id')
                                ->where('s.user_id', $userId);
                        });
                })->orWhere(function ($query) use ($userId) {
                    $query->where('el.linkable_type', 'operation_price')
                        ->whereExists(function ($subquery) use ($userId) {
                            $subquery->selectRaw('1')
                                ->from('operation_prices as op')
                                ->join('price_list_versions as plv', 'plv.id', '=', 'op.price_list_version_id')
                                ->join('price_lists as pl', 'pl.id', '=', 'plv.price_list_id')
                                ->join('suppliers as s', 's.id', '=', 'pl.supplier_id')
                                ->whereColumn('op.id', 'el.linkable_id')
                                ->where('s.user_id', $userId);
                        });
                });
            })
            ->exists();

        abort_unless(
            ($record && (int) $record->created_by === $userId)
                || (int) $asset->uploaded_by === $userId
                || $ownedLaborSource
                || $ownedFinishedProductSource
                || $ownedSupplierEvidence,
            403,
            'Access denied.',
        );

        return $storage->downloadResponse(
            $asset->storage_disk ?: 'public',
            $asset->file_path,
            $asset->original_filename ?: basename($asset->file_path),
            $asset->mime_type ?: 'application/octet-stream',
            str_starts_with((string) $asset->mime_type, 'image/'),
        );
    }
}
