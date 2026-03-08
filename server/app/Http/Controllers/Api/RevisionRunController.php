<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunRevisionUpdateJob;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Service\ReportService;
use App\Services\SnapshotService;
use App\Services\UrlNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RevisionRunController extends Controller
{
    public function __construct(
        private SnapshotService $snapshotService,
        private UrlNormalizer $urlNormalizer,
        private ReportService $reportService
    ) {}

    public function start(Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $report = $this->reportService->buildReport($project)->toArray();
        $reportItems = $this->collectReportItems($project, $report);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => auth()->id(),
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => count($reportItems),
        ]);

        foreach ($reportItems as $reportItem) {
            $sourceUrl = $this->urlNormalizer->normalize($reportItem['source_url'] ?? null);

            RevisionRunItem::create([
                'revision_run_id' => $run->id,
                'project_position_id' => $reportItem['project_position_id'],
                'material_id' => $reportItem['material_id'],
                'source_url' => $sourceUrl,
                'status' => RevisionRunItem::STATUS_PENDING,
                'message' => $sourceUrl ? null : 'Нет source URL',
            ]);
        }

        RunRevisionUpdateJob::dispatch($run->id, false);

        return response()->json([
            'success' => true,
            'run_id' => $run->id,
            'status' => $run->status,
            'total_items' => $run->total_items,
        ], 201);
    }

    public function show(Project $project, int $runId): JsonResponse
    {
        $this->authorize('view', $project);
        $run = RevisionRun::with(['items.position', 'items.material', 'items.priceHistory'])
            ->where('project_id', $project->id)
            ->findOrFail($runId);

        return response()->json([
            'run' => $run,
            'items' => $run->items,
        ]);
    }

    public function retry(Project $project, int $runId): JsonResponse
    {
        $this->authorize('update', $project);
        $run = RevisionRun::where('project_id', $project->id)->findOrFail($runId);
        RunRevisionUpdateJob::dispatch($run->id, true);

        return response()->json([
            'success' => true,
            'run_id' => $run->id,
            'status' => $run->fresh()->status,
        ]);
    }

    public function manual(Request $request, int $runId, int $itemId): JsonResponse
    {
        $validated = $request->validate([
            'price_per_unit' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:10',
            'source_url' => 'nullable|url|max:2048',
            'region_id' => 'nullable|integer',
            'screenshot_file' => 'required|file|image|max:10240',
        ]);

        $item = RevisionRunItem::with(['run.project', 'material', 'position.material', 'position.edgeMaterial', 'position.facadeMaterial'])
            ->where('revision_run_id', $runId)
            ->findOrFail($itemId);
        $this->authorize('update', $item->run->project);

        $material = $item->material
            ?: $item->position?->facadeMaterial
            ?: $item->position?->edgeMaterial
            ?: $item->position?->material;
        if (!$material) {
            return response()->json(['error' => 'Материал позиции не найден'], 422);
        }

        $path = $validated['screenshot_file']->store('screenshots/manual/' . now()->format('Y/m'), 'public');
        $rawUrl = $validated['source_url'] ?? $item->source_url ?? $material->source_url;
        $normalized = $this->urlNormalizer->normalize($rawUrl);

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => (int) ($material->version ?? 1),
            'valid_from' => now()->toDateString(),
            'price_per_unit' => (float) $validated['price_per_unit'],
            'source_url' => $normalized,
            'raw_source_url' => $rawUrl,
            'normalized_source_url' => $normalized,
            'screenshot_path' => $path,
            'observed_at' => now(),
            'region_id' => $validated['region_id'] ?? $item->run->project->region_id,
            'source_type' => MaterialPriceHistory::SOURCE_MANUAL,
            'is_verified' => false,
            'true_score' => 0,
            'currency' => strtoupper((string) $validated['currency']),
        ]);

        $item->update([
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Закрыто вручную',
            'source_url' => $normalized,
            'price_history_id' => $history->id,
        ]);

        $this->refreshRunCounters($item->run);

        return response()->json([
            'success' => true,
            'item' => $item->fresh(),
            'price_history_id' => $history->id,
        ]);
    }

    public function finalize(Project $project, int $runId): JsonResponse
    {
        $this->authorize('update', $project);
        $run = RevisionRun::with(['items.priceHistory', 'items.material'])
            ->where('project_id', $project->id)
            ->findOrFail($runId);

        $blockers = $run->items->where('status', '!=', RevisionRunItem::STATUS_OK)->values();
        if ($blockers->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Есть незакрытые позиции обоснования',
                'blockers' => $blockers,
            ], 409);
        }

        $justifications = $run->items->map(function (RevisionRunItem $item) {
            $h = $item->priceHistory;
            $material = $item->material;
            return [
                'project_position_id' => $item->project_position_id,
                'material_id' => $item->material_id,
                'name' => $material?->name ?? ('Материал #' . ($item->material_id ?: '—')),
                'article' => $material?->article,
                'unit' => $material?->unit,
                'material_type' => $material?->type,
                'price_history_id' => $item->price_history_id,
                'source_url' => $item->source_url,
                'observed_at' => $h?->observed_at?->toIso8601String(),
                'screenshot_path' => $h?->screenshot_path,
                'price_per_unit' => $h?->price_per_unit,
                'currency' => $h?->currency,
                'true_score' => $h?->true_score,
                'source_type' => $h?->source_type,
            ];
        })->values()->toArray();

        $revision = $this->snapshotService->createSnapshot(
            $project,
            auth()->id(),
            [
                'price_justifications' => $justifications,
                'revision_run_id' => $run->id,
            ]
        );

        $run->update(['status' => RevisionRun::STATUS_FINALIZED]);

        return response()->json([
            'success' => true,
            'revision' => [
                'id' => $revision->id,
                'number' => $revision->number,
            ],
            'pdf' => [
                'smeta' => url("/api/projects/{$project->id}/revisions/{$revision->number}/pdf"),
                'price_justification' => url("/api/projects/{$project->id}/revisions/{$revision->number}/price-justification.pdf"),
            ],
        ]);
    }

    private function refreshRunCounters(RevisionRun $run): void
    {
        $total = $run->items()->count();
        $ok = $run->items()->where('status', RevisionRunItem::STATUS_OK)->count();
        $failed = $total - $ok;

        $run->update([
            'status' => $failed === 0 ? RevisionRun::STATUS_READY : RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => $total,
            'ok_items' => $ok,
            'failed_items' => $failed,
            'finished_at' => $failed === 0 ? now() : null,
        ]);
    }

    private function collectReportItems(Project $project, array $report): array
    {
        $positions = $project->positions()
            ->where('kind', ProjectPosition::KIND_PANEL)
            ->with(['material', 'edgeMaterial', 'facadeMaterial'])
            ->get();

        $items = [];

        foreach ((array) ($report['plates'] ?? []) as $plate) {
            $materialId = (int) ($plate['id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }

            $position = $positions->first(fn(ProjectPosition $pos) => (int) $pos->material_id === $materialId);
            if (!$position) {
                continue;
            }

            $items['plate:' . $materialId] = [
                'project_position_id' => $position->id,
                'material_id' => $materialId,
                'source_url' => $plate['source_url'] ?? $position->material?->source_url,
            ];
        }

        foreach ((array) ($report['edges'] ?? []) as $edge) {
            $materialId = (int) ($edge['id'] ?? 0);
            if ($materialId <= 0) {
                continue;
            }

            $position = $positions->first(fn(ProjectPosition $pos) => (int) $pos->edge_material_id === $materialId);
            if (!$position) {
                continue;
            }

            $items['edge:' . $materialId] = [
                'project_position_id' => $position->id,
                'material_id' => $materialId,
                'source_url' => $edge['source_url'] ?? $position->edgeMaterial?->source_url,
            ];
        }

        return array_values($items);
    }
}
