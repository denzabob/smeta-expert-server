<?php

namespace App\Http\Controllers\Api\Pricing\Labor;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaborEvidenceAssetRequest;
use App\Models\GenericEvidenceAsset;
use App\Models\LaborEvidenceSource;
use App\Services\LaborEvidenceAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LaborEvidenceAssetController extends Controller
{
    public function __construct(
        private readonly LaborEvidenceAssetService $assetService,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $source = $this->findOwnedSource($request, $id);
        $assets = $source->evidenceRecord?->assets()
            ->orderByDesc('id')
            ->get() ?? collect();

        return response()->json([
            'data' => $assets,
        ]);
    }

    public function store(StoreLaborEvidenceAssetRequest $request, int $id): JsonResponse
    {
        $source = $this->findOwnedSource($request, $id);
        $assetType = $request->input('type', 'screenshot');

        $asset = $this->assetService->store(
            $source,
            $request->file('file'),
            $assetType,
            (int) $request->user()->id,
        );

        return response()->json($asset, 201);
    }

    public function destroy(Request $request, int $id, int $assetId): JsonResponse
    {
        $source = $this->findOwnedSource($request, $id);
        $record = $source->evidenceRecord;

        abort_if(!$record, 404, 'Evidence record not found.');

        $asset = GenericEvidenceAsset::query()
            ->where('evidence_record_id', $record->id)
            ->findOrFail($assetId);

        $this->assetService->delete($asset);

        return response()->json([
            'success' => true,
        ]);
    }

    private function findOwnedSource(Request $request, int $id): LaborEvidenceSource
    {
        return LaborEvidenceSource::query()
            ->ownedBy((int) $request->user()->id)
            ->with('evidenceRecord')
            ->findOrFail($id);
    }
}
