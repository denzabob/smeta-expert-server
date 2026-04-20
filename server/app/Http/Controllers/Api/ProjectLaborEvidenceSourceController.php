<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaborEvidenceSource;
use App\Models\Project;
use App\Services\ProjectLaborWorkRateApplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectLaborEvidenceSourceController extends Controller
{
    public function __construct(
        private readonly ProjectLaborWorkRateApplierService $rateApplier,
    ) {
    }

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $sources = $project->laborEvidenceSources()
            ->with(['provider', 'region', 'laborProfile', 'evidenceRecord.assets'])
            ->orderByDesc('labor_evidence_sources.id')
            ->get();

        return response()->json([
            'data' => $sources,
        ]);
    }

    public function attach(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'source_ids' => 'required|array|min:1',
            'source_ids.*' => 'integer|min:1',
        ]);

        $sourceIds = array_values(array_unique(array_map('intval', $validated['source_ids'])));
        $sources = LaborEvidenceSource::query()
            ->ownedBy((int) $request->user()->id)
            ->whereIn('id', $sourceIds)
            ->whereNotNull('evidence_record_id')
            ->get();

        if ($sources->count() !== count($sourceIds)) {
            return response()->json([
                'message' => 'One or more labor evidence sources are invalid, чужие, or missing evidence_record_id.',
            ], 422);
        }

        $project->laborEvidenceSources()->syncWithoutDetaching($sourceIds);
        $this->rateApplier->apply($project);

        Log::info('labor_rates_reapplied_after_evidence_attach', [
            'project_id' => $project->id,
            'attached_count' => count($sourceIds),
        ]);

        return response()->json([
            'success' => true,
            'attached_count' => count($sourceIds),
            'data' => $project->laborEvidenceSources()
                ->with(['provider', 'region', 'laborProfile', 'evidenceRecord.assets'])
                ->orderByDesc('labor_evidence_sources.id')
                ->get(),
        ]);
    }

    public function detach(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'source_ids' => 'required|array|min:1',
            'source_ids.*' => 'integer|min:1',
        ]);

        $sourceIds = array_values(array_unique(array_map('intval', $validated['source_ids'])));
        $ownedIds = LaborEvidenceSource::query()
            ->ownedBy((int) $request->user()->id)
            ->whereIn('id', $sourceIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($ownedIds) !== count($sourceIds)) {
            return response()->json([
                'message' => 'One or more labor evidence sources are invalid or чужие.',
            ], 422);
        }

        $project->laborEvidenceSources()->detach($ownedIds);
        $this->rateApplier->apply($project);

        Log::info('labor_rates_reapplied_after_evidence_detach', [
            'project_id' => $project->id,
            'detached_count' => count($ownedIds),
        ]);

        return response()->json([
            'success' => true,
            'detached_count' => count($ownedIds),
            'data' => $project->laborEvidenceSources()
                ->with(['provider', 'region', 'laborProfile', 'evidenceRecord.assets'])
                ->orderByDesc('labor_evidence_sources.id')
                ->get(),
        ]);
    }
}
