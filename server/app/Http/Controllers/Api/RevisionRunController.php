<?php

namespace App\Http\Controllers\Api;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Evidence\EvidenceFeatures;
use App\Http\Controllers\Controller;
use App\Jobs\RunRevisionUpdateJob;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        if (($report['totals']['total_is_valid'] ?? true) === false) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_estimate',
                'message' => 'Смета содержит ошибки и не может быть использована',
            ], 422);
        }

        $reportItems = $this->collectReportItems($project, $report);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => auth()->id(),
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => count($reportItems),
        ]);

        foreach ($reportItems as $reportItem) {
            $sourceUrl = $this->urlNormalizer->normalize($reportItem['source_url'] ?? null);

            $initialStatus = $reportItem['initial_status'] ?? RevisionRunItem::STATUS_PENDING;
            $initialMessage = $reportItem['initial_message']
                ?? ($sourceUrl ? null : 'Нет source URL');

            $item = RevisionRunItem::create([
                'revision_run_id' => $run->id,
                'project_position_id' => $reportItem['project_position_id'] ?? null,
                'project_fitting_id' => $reportItem['project_fitting_id'] ?? null,
                'material_id' => $reportItem['material_id'] ?? null,
                'source_url' => $sourceUrl,
                'status' => $initialStatus,
                'message' => $initialMessage,
                'cost_driver_type' => $reportItem['cost_driver_type'] ?? null,
                'evidence_subject_type' => $reportItem['evidence_subject_type'] ?? null,
                'evidence_subject_id' => $reportItem['evidence_subject_id'] ?? null,
            ]);

            // Internal-only cost drivers (e.g. operations) are pre-resolved:
            // create an EvidenceArtifact inline instead of deferring to scraping.
            if (in_array($reportItem['cost_driver_type'] ?? null, CostDriverType::internalOnlyTypes(), true)) {
                EvidenceArtifact::create([
                    'uuid'                 => (string) Str::uuid(),
                    'revision_run_id'      => $run->id,
                    'revision_run_item_id' => $item->id,
                    'mode'                 => EvidenceArtifact::MODE_AUTO,
                    'capture_source'       => CaptureSource::INTERNAL,
                    'cost_driver_type'     => $reportItem['cost_driver_type'],
                    'extracted_price'      => $reportItem['_operation_price'] ?? $reportItem['_labor_rate'] ?? $reportItem['_expense_amount'] ?? null,
                    'currency'             => 'RUB',
                    'extracted_name'       => $reportItem['_operation_name'] ?? $reportItem['_labor_title'] ?? $reportItem['_expense_name'] ?? null,
                    // Expenses get trust_score=50: user-declared amount with no
                    // independent verification.  Operations/labor use 100 (computed).
                    'trust_score'          => ($reportItem['cost_driver_type'] === CostDriverType::EXPENSE) ? 50 : 100,
                    'captured_at'          => now(),
                    'created_by'           => auth()->id(),
                ]);
            }
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
        $run = RevisionRun::with([
                'items.position',
                'items.projectFitting.material',
                'items.material',
                'items.priceHistory',
                'items.evidenceArtifacts:id,revision_run_item_id,capture_source,mode,extracted_price,currency,source_url_raw,source_domain,captured_at,created_at,trust_score',
                'items.evidenceArtifacts.assets:id,evidence_artifact_id,asset_type,mime_type,original_filename,file_size',
            ])
            ->where('project_id', $project->id)
            ->findOrFail($runId);

        $run->items->each(function ($item) {
            $artifact = $item->evidenceArtifacts->first();
            $item->resolved_capture_source = $artifact?->capture_source;
            $item->has_evidence = $item->evidenceArtifacts->flatMap->assets->isNotEmpty();
        });

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

        $item = RevisionRunItem::with(['run.project', 'material', 'projectFitting.material', 'position.material', 'position.edgeMaterial', 'position.facadeMaterial'])
            ->where('revision_run_id', $runId)
            ->findOrFail($itemId);
        $this->authorize('update', $item->run->project);

        $material = $item->material
            ?: $item->projectFitting?->material
            ?: $item->position?->facadeMaterial
            ?: $item->position?->edgeMaterial
            ?: $item->position?->material;
        if (!$material) {
            return response()->json(['error' => 'Материал позиции не найден'], 422);
        }

        $uploadedFile = $validated['screenshot_file'];
        $path = $uploadedFile->store('screenshots/manual/' . now()->format('Y/m'), 'public');
        $rawUrl = $validated['source_url'] ?? $item->source_url ?? $material->source_url;
        $normalized = $this->urlNormalizer->normalize($rawUrl);

        // File upload above is non-transactional; DB writes below are atomic.
        // If the transaction fails, the uploaded file remains as an orphan on disk.
        $result = DB::transaction(function () use ($item, $material, $validated, $path, $rawUrl, $normalized, $uploadedFile) {
            $artifact = EvidenceArtifact::create([
                'uuid'                  => (string) Str::uuid(),
                'material_id'           => $material->id,
                'revision_run_id'       => $item->revision_run_id,
                'revision_run_item_id'  => $item->id,
                'mode'                  => EvidenceArtifact::MODE_MANUAL,
                'capture_source'        => CaptureSource::MANUAL,
                'cost_driver_type'      => $item->cost_driver_type,
                'source_url_raw'        => $rawUrl,
                'source_url_normalized' => $normalized,
                'source_domain'         => $normalized ? (parse_url($normalized, PHP_URL_HOST) ?: null) : null,
                'extracted_price'       => (float) $validated['price_per_unit'],
                'currency'              => strtoupper((string) $validated['currency']),
                'screenshot_path'       => $path,
                'trust_score'           => 60,
                'captured_at'           => now(),
                'created_by'            => auth()->id(),
            ]);

            EvidenceAsset::create([
                'uuid'                 => (string) Str::uuid(),
                'evidence_artifact_id' => $artifact->id,
                'asset_type'           => 'screenshot',
                'file_path'            => $path,
                'original_filename'    => $uploadedFile->getClientOriginalName(),
                'mime_type'            => $uploadedFile->getClientMimeType(),
                'file_size'            => $uploadedFile->getSize(),
            ]);

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
                'evidence_artifact_id' => $artifact->id,
                'evidence_mode' => EvidenceArtifact::MODE_MANUAL,
            ]);

            $item->update([
                'status' => RevisionRunItem::STATUS_OK,
                'message' => 'Закрыто вручную',
                'source_url' => $normalized,
                'price_history_id' => $history->id,
            ]);

            $this->refreshRunCounters($item->run);

            return $history;
        });

        return response()->json([
            'success' => true,
            'item' => $item->fresh(),
            'price_history_id' => $result->id,
        ]);
    }

    public function finalize(Project $project, int $runId): JsonResponse
    {
        $this->authorize('update', $project);

        $report = $this->reportService->buildReport($project)->toArray();
        if (($report['totals']['total_is_valid'] ?? true) === false) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_estimate',
                'message' => 'Смета содержит ошибки и не может быть использована',
            ], 422);
        }

        $run = RevisionRun::with(['items.priceHistory', 'items.material', 'items.projectFitting', 'items.evidenceArtifacts.assets', 'items.evidenceSubject'])
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
            $artifact = $item->evidenceArtifacts->first();

            // Internal-only cost drivers (operations, labor_work, expenses): read from evidenceSubject + artifact
            if (in_array($item->cost_driver_type, CostDriverType::internalOnlyTypes(), true)) {
                $subject = $item->evidenceSubject;
                $isLaborWork = $item->cost_driver_type === CostDriverType::LABOR_WORK;
                $isExpense   = $item->cost_driver_type === CostDriverType::EXPENSE;

                // source_type: operations = price_list, labor_work = rate_computation,
                // expenses = user_declared (unverified user input)
                $sourceType = $isExpense ? 'user_declared' : ($isLaborWork ? 'rate_computation' : 'price_list');

                // Enrich expense rows with document path when a supporting document was attached.
                $expenseDocExtra = [];
                if ($isExpense && $artifact) {
                    $docAsset = $artifact->assets->firstWhere('asset_type', 'document');
                    $expenseDocExtra = [
                        'expense_document_path' => $docAsset?->file_path,
                        'expense_document_mime' => $docAsset?->mime_type,
                    ];
                }

                // Enrich labor_work rows with hours, basis, note, total_cost, and steps.
                // Steps are loaded lazily inside this branch only to avoid polymorphic eager-load
                // issues on Expense/Operation subjects that have no steps() relation.
                $laborWorkExtra = [];
                if ($isLaborWork && $subject instanceof \App\Models\ProjectLaborWork) {
                    $subject->loadMissing('steps');
                    $laborWorkExtra = [
                        'labor_work_hours'      => (float) $subject->hours,
                        'labor_work_basis'      => $subject->basis ?? null,
                        'labor_work_note'       => $subject->note ?? null,
                        'labor_work_total_cost' => $subject->cost,
                        'labor_work_steps'      => $subject->steps->map(fn ($s) => [
                            'title' => $s->title,
                            'hours' => (float) $s->hours,
                            'basis' => $s->basis ?? null,
                            'note'  => $s->note ?? null,
                        ])->toArray(),
                    ];
                }

                return array_merge([
                    'project_position_id' => null,
                    'project_fitting_id'  => null,
                    'material_id'         => null,
                    'name'                => $subject?->name ?? $subject?->title ?? $artifact?->extracted_name ?? ($item->cost_driver_type . ' #' . $item->evidence_subject_id),
                    'article'             => null,
                    'unit'                => $subject?->unit ?? ($isLaborWork ? 'н/ч' : null),
                    'material_type'       => null,
                    'price_history_id'    => null,
                    'source_url'          => null,
                    'observed_at'         => $artifact?->captured_at?->toIso8601String(),
                    'screenshot_path'     => null,
                    'price_per_unit'      => $artifact?->extracted_price !== null ? (float) $artifact->extracted_price : null,
                    'currency'            => $artifact?->currency ?? 'RUB',
                    'true_score'          => $artifact?->trust_score,
                    'source_type'         => $sourceType,
                    'capture_source'      => $artifact?->capture_source,
                    'cost_driver_type'    => $item->cost_driver_type,
                    'source_domain'       => null,
                ], $laborWorkExtra, $expenseDocExtra);
            }

            // Material-based cost drivers (plate, edge, fitting, facade): existing path
            $h = $item->priceHistory;
            $material = $item->material;
            $fitting = $item->projectFitting;
            return [
                'project_position_id' => $item->project_position_id,
                'project_fitting_id' => $item->project_fitting_id,
                'material_id' => $item->material_id,
                'name' => $material?->name
                    ?? $fitting?->name
                    ?? ('Материал #' . ($item->material_id ?: $item->project_fitting_id ?: '—')),
                'article' => $material?->article ?? $fitting?->article,
                'unit' => $material?->unit ?? $fitting?->unit,
                'material_type' => $material?->type,
                'price_history_id' => $item->price_history_id,
                'source_url' => $item->source_url,
                'observed_at' => $h?->observed_at?->toIso8601String(),
                'screenshot_path' => $h?->screenshot_path,
                'price_per_unit' => $h?->price_per_unit,
                'currency' => $h?->currency,
                'true_score' => $h?->true_score,
                'source_type' => $h?->source_type,
                'capture_source' => $artifact?->capture_source,
                'cost_driver_type' => $item->cost_driver_type,
                'source_domain' => $artifact?->source_domain,
            ];
        })->values()->toArray();

        $totalItems = count($justifications);
        $withEvidence = collect($justifications)->filter(fn ($j) => !empty($j['capture_source']))->count();
        $bySource = collect($justifications)
            ->filter(fn ($j) => !empty($j['capture_source']))
            ->groupBy('capture_source')
            ->map->count()
            ->toArray();

        $evidenceSummary = [
            'total_items' => $totalItems,
            'with_evidence' => $withEvidence,
            'coverage_pct' => $totalItems > 0 ? round(($withEvidence / $totalItems) * 100, 1) : 0,
            'by_capture_source' => $bySource,
        ];

        $revision = $this->snapshotService->createSnapshot(
            $project,
            auth()->id(),
            [
                'price_justifications' => $justifications,
                'evidence_summary' => $evidenceSummary,
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

    /**
     * Attach an optional document/receipt to an expense revision item.
     * Bumps the artifact trust_score from 50 → 75 (capped).
     *
     * File upload is non-transactional; DB writes are atomic.
     * If the transaction fails, the uploaded file remains as an orphan on disk.
     */
    public function attachDocument(Request $request, int $runId, int $itemId): JsonResponse
    {
        if (!EvidenceFeatures::expensesDocumentEnabled()) {
            return response()->json(['error' => 'Expense document enrichment is not enabled'], 403);
        }

        $validated = $request->validate([
            'document_file' => 'required|file|mimes:jpeg,png,webp,pdf|max:10240',
        ]);

        $item = RevisionRunItem::with(['run.project', 'evidenceArtifacts'])
            ->where('revision_run_id', $runId)
            ->findOrFail($itemId);

        $this->authorize('update', $item->run->project);

        if ($item->cost_driver_type !== CostDriverType::EXPENSE) {
            return response()->json(['error' => 'Document attachment is only available for expense items'], 422);
        }

        if ($item->run->status === RevisionRun::STATUS_FINALIZED) {
            return response()->json(['error' => 'Cannot attach documents to a finalized run'], 409);
        }

        $artifact = $item->evidenceArtifacts->first();
        if (!$artifact) {
            return response()->json(['error' => 'No evidence artifact found for this item'], 422);
        }

        $uploadedFile = $validated['document_file'];
        $path = $uploadedFile->store('evidence/documents/expenses/' . now()->format('Y/m'), 'public');

        $asset = DB::transaction(function () use ($artifact, $uploadedFile, $path) {
            $asset = EvidenceAsset::create([
                'uuid'                 => (string) Str::uuid(),
                'evidence_artifact_id' => $artifact->id,
                'asset_type'           => 'document',
                'file_path'            => $path,
                'original_filename'    => $uploadedFile->getClientOriginalName(),
                'mime_type'            => $uploadedFile->getClientMimeType(),
                'file_size'            => $uploadedFile->getSize(),
            ]);

            // Bump trust_score to 75 (user-declared + supporting document).
            // Idempotent: stays at 75 for additional uploads.
            if ($artifact->trust_score < 75) {
                $artifact->update(['trust_score' => 75]);
            }

            return $asset;
        });

        return response()->json([
            'success' => true,
            'asset' => [
                'id' => $asset->id,
                'uuid' => $asset->uuid,
                'asset_type' => $asset->asset_type,
                'mime_type' => $asset->mime_type,
                'original_filename' => $asset->original_filename,
                'file_size' => $asset->file_size,
            ],
            'trust_score' => $artifact->fresh()->trust_score,
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
                'cost_driver_type' => CostDriverType::PLATE,
                'evidence_subject_type' => 'project_position',
                'evidence_subject_id' => $position->id,
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
                'project_fitting_id' => null,
                'material_id' => $materialId,
                'source_url' => $edge['source_url'] ?? $position->edgeMaterial?->source_url,
                'cost_driver_type' => CostDriverType::EDGE,
                'evidence_subject_type' => 'project_position',
                'evidence_subject_id' => $position->id,
            ];
        }

        $fittings = $project->fittings()->with('material')->get();
        foreach ($fittings as $fitting) {
            $material = $fitting->material;
            if (!$material) {
                continue;
            }

            $items['hardware_fitting:' . $fitting->id] = [
                'project_position_id' => null,
                'project_fitting_id' => $fitting->id,
                'material_id' => $material->id,
                'source_url' => $fitting->source_url ?: $material->source_url,
                'cost_driver_type' => CostDriverType::FITTING,
                'evidence_subject_type' => 'project_fitting',
                'evidence_subject_id' => $fitting->id,
            ];
        }

        // Facade materials — manual-only items (no auto-scraping)
        if (EvidenceFeatures::facadeEvidenceEnabled()) {
            $facadePositions = $project->positions()
                ->where('kind', ProjectPosition::KIND_FACADE)
                ->with('facadeMaterial')
                ->get();

            foreach ($facadePositions as $pos) {
                $material = $pos->facadeMaterial;
                if (!$material) {
                    continue;
                }

                // One item per unique facade material (matches report aggregation)
                if (isset($items['facade:' . $material->id])) {
                    continue;
                }

                $items['facade:' . $material->id] = [
                    'project_position_id' => $pos->id,
                    'project_fitting_id' => null,
                    'material_id' => $material->id,
                    'source_url' => $material->source_url,
                    'cost_driver_type' => CostDriverType::FACADE,
                    'evidence_subject_type' => 'project_position',
                    'evidence_subject_id' => $pos->id,
                    'initial_status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
                    'initial_message' => 'Фасады: только ручное подтверждение',
                ];
            }
        }

        // Operations — internal evidence, auto-closed at creation.
        // Dedup key uses operation_id. The report's aggregateOperationsForReport()
        // guarantees one OperationAggregateDto per unique (id, name, unit, price).
        // In practice each operation_id appears once because OperationPriceResolver
        // returns one price per operation. If that invariant ever breaks, the first
        // occurrence wins (matching existing facade dedup behaviour).
        if (EvidenceFeatures::operationsEvidenceEnabled()) {
            foreach ((array) ($report['operations'] ?? []) as $op) {
                $operationId = (int) ($op['id'] ?? 0);
                if ($operationId <= 0) {
                    continue;
                }

                if (isset($items['operation:' . $operationId])) {
                    continue;
                }

                $items['operation:' . $operationId] = [
                    'project_position_id' => null,
                    'project_fitting_id' => null,
                    'material_id' => null,
                    'source_url' => null,
                    'cost_driver_type' => CostDriverType::OPERATION,
                    'evidence_subject_type' => 'operation',
                    'evidence_subject_id' => $operationId,
                    'initial_status' => RevisionRunItem::STATUS_OK,
                    'initial_message' => 'Внутренний источник: прайс-лист поставщика',
                    // Transient metadata for EvidenceArtifact creation in start();
                    // not stored in revision_run_items.
                    '_operation_price' => (float) ($op['cost_per_unit'] ?? 0),
                    '_operation_name' => $op['name'] ?? '',
                    '_operation_unit' => $op['unit'] ?? '',
                ];
            }
        }

        // Labor works — internal evidence, auto-closed at creation.
        // One item per ProjectLaborWork row. The rate (₽/hour) comes from
        // the project's profile rate or normohour_rate fallback.
        if (EvidenceFeatures::laborWorkEvidenceEnabled()) {
            foreach ((array) ($report['labor_works'] ?? []) as $lw) {
                $lwId = (int) ($lw['id'] ?? 0);
                if ($lwId <= 0) {
                    continue;
                }

                if (isset($items['labor_work:' . $lwId])) {
                    continue;
                }

                $items['labor_work:' . $lwId] = [
                    'project_position_id' => null,
                    'project_fitting_id' => null,
                    'material_id' => null,
                    'source_url' => null,
                    'cost_driver_type' => CostDriverType::LABOR_WORK,
                    'evidence_subject_type' => 'project_labor_work',
                    'evidence_subject_id' => $lwId,
                    'initial_status' => RevisionRunItem::STATUS_OK,
                    'initial_message' => 'Внутренний источник: расчёт ставки нормо-часа',
                    // Transient metadata for EvidenceArtifact creation in start();
                    // not stored in revision_run_items.
                    '_labor_rate' => (float) ($lw['rate_per_hour'] ?? 0),
                    '_labor_title' => $lw['title'] ?? '',
                    '_labor_unit' => 'н/ч',
                ];
            }
        }

        // Expenses — user-declared costs, auto-closed at creation.
        // One item per Expense row. The amount comes from the user's input.
        // ExpenseDto maps Expense.name → 'type' and Expense.amount → 'cost'.
        if (EvidenceFeatures::expensesEvidenceEnabled()) {
            foreach ((array) ($report['expenses'] ?? []) as $exp) {
                $expId = (int) ($exp['id'] ?? 0);
                if ($expId <= 0) {
                    continue;
                }

                if (isset($items['expense:' . $expId])) {
                    continue;
                }

                $items['expense:' . $expId] = [
                    'project_position_id' => null,
                    'project_fitting_id' => null,
                    'material_id' => null,
                    'source_url' => null,
                    'cost_driver_type' => CostDriverType::EXPENSE,
                    'evidence_subject_type' => 'expense',
                    'evidence_subject_id' => $expId,
                    'initial_status' => RevisionRunItem::STATUS_OK,
                    'initial_message' => 'Пользовательский расход: сумма задана вручную',
                    // Transient metadata for EvidenceArtifact creation in start();
                    // not stored in revision_run_items.
                    '_expense_amount' => (float) ($exp['cost'] ?? 0),
                    '_expense_name' => $exp['type'] ?? '',
                ];
            }
        }

        return array_values($items);
    }
}
