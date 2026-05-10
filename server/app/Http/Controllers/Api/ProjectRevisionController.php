<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RecordsUsageEvents;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RevisionPublication;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Service\ReportService;
use App\Services\Billing\BillingCodes;
use App\Services\FinishedProductFacadeRevisionRowAssembler;
use App\Services\FinishedProductFacadeSnapshotPresenter;
use App\Services\ProjectReportReadinessService;
use App\Services\Reports\ReportSettingsResolver;
use App\Services\SnapshotService;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectRevisionController extends Controller
{
    use RecordsUsageEvents;

    public function __construct(
        private SnapshotService $snapshotService,
        private ReportService $reportService,
        private FinishedProductFacadeRevisionRowAssembler $finishedProductFacadeRevisionRowAssembler,
        private FinishedProductFacadeSnapshotPresenter $finishedProductFacadeSnapshotPresenter,
        private ReportSettingsResolver $reportSettingsResolver,
        private ProjectReportReadinessService $reportReadinessService,
    ) {}

    /**
     * Создать новую ревизию (snapshot) проекта
     * 
     * POST /api/projects/{id}/revisions
     * 
     * @param Project $project
     * @return JsonResponse
     */
    public function store(Project $project): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('update', $project);

        try {
            $prepared = $this->snapshotService->buildSnapshotData($project);
            $report = $prepared['snapshot'];
            if (($report['totals']['total_is_valid'] ?? true) === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'invalid_estimate',
                    'message' => 'Смета содержит ошибки и не может быть использована',
                ], 422);
            }

            if (!$this->reportReadinessService->hasMeaningfulEstimateContent($report)) {
                return response()->json([
                    'success' => false,
                    'code' => 'empty_project',
                    'message' => 'Смету пока нельзя создать: в проекте нет расчетных позиций.',
                ], 422);
            }

            $latestRevision = $this->latestActualRevision($project);
            if (
                $latestRevision
                && $this->reportReadinessService->estimateSnapshotHashForRevision($latestRevision) === $prepared['snapshot_hash']
            ) {
                return response()->json([
                    'success' => true,
                    'status' => 'unchanged',
                    'message' => 'Актуальный отчет уже создан. Смета не изменилась после последнего формирования.',
                    'revision' => $this->revisionPayload($latestRevision),
                    'pdf_url' => url("/api/projects/{$project->id}/revisions/{$latestRevision->number}/pdf"),
                    'revision_id' => $latestRevision->id,
                    'number' => $latestRevision->number,
                    'created_at' => $latestRevision->created_at?->toIso8601String(),
                ]);
            }

            $revision = $this->snapshotService->createSnapshotFromPrepared(
                $project,
                auth()->id(),
                $prepared
            );

            $this->recordUsageEvent(BillingCodes::METRIC_REVISIONS_CREATED, 1, [
                'project' => $project,
                'feature_code' => BillingCodes::FEATURE_REVISIONS,
                'subject_type' => ProjectRevision::class,
                'subject_id' => $revision->id,
                'unit' => 'count',
                'source' => 'api',
                'metadata' => [
                    'controller' => static::class,
                    'action' => __FUNCTION__,
                    'revision_number' => $revision->number,
                ],
            ]);

            return response()->json([
                'success' => true,
                'status' => 'created',
                'revision_id' => $revision->id,
                'number' => $revision->number,
                'snapshot_hash' => $revision->snapshot_hash,
                'created_at' => $revision->created_at->toIso8601String(),
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Failed to create project revision', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Ошибка при создании ревизии',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить список ревизий проекта
     * 
     * GET /api/projects/{id}/revisions
     * 
     * @param Project $project
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Project $project, Request $request): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('view', $project);

        $perPage = $request->input('per_page', 15);
        
        $revisions = $project->revisions()
            ->with('createdBy:id,name,email')
            ->orderByDesc('number')
            ->paginate($perPage);

        $items = $revisions->getCollection()->map(function ($revision) {
            return [
                'id' => $revision->id,
                'number' => $revision->number,
                'status' => $revision->status,
                'created_at' => $revision->created_at?->toIso8601String(),
                'snapshot_hash' => $revision->snapshot_hash,
                'created_by' => $revision->createdBy,
            ];
        });

        return response()->json([
            'success' => true,
            'revisions' => $items,
            'pagination' => [
                'current_page' => $revisions->currentPage(),
                'last_page' => $revisions->lastPage(),
                'per_page' => $revisions->perPage(),
                'total' => $revisions->total(),
            ],
        ]);
    }

    /**
     * Получить конкретную ревизию проекта
     * 
     * GET /api/projects/{id}/revisions/{number}
     * 
     * @param Project $project
     * @param int $number Номер ревизии
     * @return JsonResponse
     */
    public function show(Project $project, int $number): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->with('createdBy:id,name,email')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'revision' => $revision,
        ]);
    }

    /**
     * Опубликовать ревизию
     * 
     * POST /api/projects/{id}/revisions/{number}/publish
     * 
     * @param Project $project
     * @param int $number
     * @return JsonResponse
     */
    public function publish(Project $project, int $number): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('update', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        $snapshot = $this->decodeRevisionSnapshot($revision);
        if (is_array($snapshot) && (($snapshot['totals']['total_is_valid'] ?? true) === false)) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_estimate',
                'message' => 'Смета содержит ошибки и не может быть использована',
            ], 422);
        }

        if ($revision->publish()) {
            $publication = RevisionPublication::firstOrCreate(
                ['project_revision_id' => $revision->id],
                [
                    'public_id' => $this->generatePublicId(),
                    'is_active' => true,
                    'access_level' => 'public_readonly',
                ]
            );

            if (!$publication->is_active) {
                $publication->is_active = true;
                $publication->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Ревизия опубликована',
                'revision' => $revision->fresh(),
                'publication' => [
                    'public_id' => $publication->public_id,
                    'public_url' => $this->makePublicVerificationUrl($publication->public_id),
                    'access_level' => $publication->access_level,
                    'is_active' => $publication->is_active,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Не удалось опубликовать ревизию',
        ], 400);
    }

    /**
     * Снять публикацию (отозвать) ревизии
     *
     * POST /api/projects/{id}/revisions/{number}/unpublish
     */
    public function unpublish(Project $project, int $number): JsonResponse
    {
        $this->authorize('update', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->status !== 'published') {
            return response()->json([
                'success' => false,
                'error' => 'Ревизия не опубликована',
            ], 400);
        }

        $publication = RevisionPublication::where('project_revision_id', $revision->id)
            ->orderByDesc('created_at')
            ->first();

        if ($publication) {
            $publication->is_active = false;
            $publication->save();
        }

        if ($revision->markStale()) {
            return response()->json([
                'success' => true,
                'message' => 'Публикация отозвана, ревизия помечена как stale',
                'revision' => $revision->fresh(),
                'publication' => $publication ? [
                    'public_id' => $publication->public_id,
                    'is_active' => $publication->is_active,
                ] : null,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Не удалось отозвать публикацию',
        ], 400);
    }

    /**
     * Заблокировать ревизию
     * 
     * POST /api/projects/{id}/revisions/{number}/lock
     * 
     * @param Project $project
     * @param int $number
     * @return JsonResponse
     */
    public function lock(Project $project, int $number): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('update', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->lock()) {
            return response()->json([
                'success' => true,
                'message' => 'Ревизия заблокирована',
                'revision' => $revision->fresh(),
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'Не удалось заблокировать ревизию',
        ], 400);
    }

    /**
     * Получить последнюю ревизию проекта
     * 
     * GET /api/projects/{id}/revisions/latest
     * 
     * @param Project $project
     * @return JsonResponse
     */
    public function latest(Project $project): JsonResponse
    {
        // Проверить права доступа
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->orderByDesc('number')
            ->first();

        if (!$revision) {
            return response()->json([
                'success' => true,
                'revision' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'revision' => [
                'id' => $revision->id,
                'number' => $revision->number,
                'status' => $revision->status,
                'created_at' => $revision->created_at->toIso8601String(),
                'snapshot_hash' => $revision->snapshot_hash,
            ],
        ]);
    }

    /**
     * Сгенерировать PDF из ревизии
     *
     * GET /api/projects/{id}/revisions/{number}/pdf
     */
    public function pdf(Project $project, int $number)
    {
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->status === 'stale') {
            return response()->json([
                'error' => 'Ревизия устарела и недоступна для PDF',
            ], 403);
        }

        $this->checkBillingGateSafely(request()->user(), BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT, [
            'action' => 'pdf.export',
            'project_id' => $project->id,
            'revision_id' => $revision->id,
            'revision_number' => $revision->number,
        ]);

        $snapshotRaw = $revision->getRawOriginal('snapshot_json');
        if (is_array($snapshotRaw)) {
            $snapshot = $snapshotRaw;
        } elseif (is_string($snapshotRaw)) {
            $snapshot = json_decode($snapshotRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($snapshot)) {
                $jsonError = json_last_error_msg();
                if (json_last_error() === JSON_ERROR_UTF8 && function_exists('mb_convert_encoding')) {
                    $normalized = mb_convert_encoding($snapshotRaw, 'UTF-8', 'UTF-8');
                    $snapshot = json_decode($normalized, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($snapshot)) {
                        $snapshotRaw = $normalized;
                    }
                }
            }
            if (is_string($snapshot)) {
                $snapshotSecond = json_decode($snapshot, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($snapshotSecond)) {
                    $snapshot = $snapshotSecond;
                }
            }
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($snapshot)) {
                \Log::warning('Revision PDF snapshot_json decode failed', [
                    'project_id' => $project->id,
                    'revision_number' => $revision->number,
                    'json_error' => json_last_error_msg(),
                    'snapshot_length' => strlen($snapshotRaw),
                    'snapshot_prefix' => substr($snapshotRaw, 0, 200),
                ]);
                return response()->json([
                    'error' => 'Некорректный snapshot_json',
                    'details' => json_last_error_msg(),
                ], 422);
            }
        } else {
            \Log::warning('Revision PDF snapshot_json missing', [
                'project_id' => $project->id,
                'revision_number' => $revision->number,
                'snapshot_type' => gettype($snapshotRaw),
            ]);
            return response()->json([
                'error' => 'Отсутствует snapshot_json',
            ], 422);
        }

        if (($snapshot['totals']['total_is_valid'] ?? true) === false) {
            return response()->json([
                'error' => 'invalid_estimate',
                'message' => 'Смета содержит ошибки и не может быть использована',
            ], 422);
        }

        $reportSettings = $this->reportSettingsResolver->forSnapshot($snapshot, $project);

        $pdf = Pdf::loadView('reports.smeta', [
            'report' => $snapshot,
            'reportSettings' => $reportSettings,
            'qrSvg' => $this->makeQrSvg($this->getPublicUrlForRevision($revision)),
            'revisionNumber' => $revision->number,
            'revisionDate' => $revision->created_at?->format('d.m.Y'),
            'snapshotHashShort' => $revision->snapshot_hash
                ? (substr($revision->snapshot_hash, 0, 8) . '…' . substr($revision->snapshot_hash, -8))
                : null,
            'engineVersion' => $revision->calculation_engine_version,
            'documentToken' => $this->getDocumentTokenForRevision($revision),
        ])
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));

        $rawFilename = "smeta_{$project->number}_rev_{$revision->number}.pdf";
        $filename = preg_replace('#[\\/:*?"<>|]#', '_', $rawFilename);

        $this->recordUsageEvent(BillingCodes::METRIC_PDF_REVISION_GENERATED, 1, [
            'project' => $project,
            'feature_code' => BillingCodes::FEATURE_PDF_REVISION,
            'subject_type' => ProjectRevision::class,
            'subject_id' => $revision->id,
            'unit' => 'count',
            'source' => 'api',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
                'revision_number' => $revision->number,
            ],
        ]);

        return $pdf->download($filename);
    }

    /**
     * Отдельный PDF "Обоснование цен" по snapshot ревизии.
     */
    public function priceJustificationPdf(Project $project, int $number)
    {
        $this->authorize('view', $project);

        $revision = $project->revisions()
            ->where('number', $number)
            ->firstOrFail();

        if ($revision->status === 'stale') {
            return response()->json([
                'error' => 'Ревизия устарела и недоступна для PDF',
            ], 403);
        }

        $this->checkBillingGateSafely(request()->user(), BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT, [
            'action' => 'pdf.price_justification',
            'project_id' => $project->id,
            'revision_id' => $revision->id,
            'revision_number' => $revision->number,
        ]);

        $snapshotRaw = $revision->getRawOriginal('snapshot_json');
        $snapshot = is_string($snapshotRaw)
            ? (json_decode($snapshotRaw, true) ?: [])
            : (is_array($snapshotRaw) ? $snapshotRaw : []);
        $reportSettings = $this->reportSettingsResolver->forSnapshot($snapshot, $project);

        if (($snapshot['totals']['total_is_valid'] ?? true) === false) {
            return response()->json([
                'error' => 'invalid_estimate',
                'message' => 'Смета содержит ошибки и не может быть использована',
            ], 422);
        }

        $justifications = is_array($snapshot['price_justifications'] ?? null)
            ? $snapshot['price_justifications']
            : [];

        $rows = collect($justifications)->map(function (array $j) {
            $normalized = ($j['reference_type'] ?? null) === 'snapshot_summary'
                ? $this->finishedProductFacadeRevisionRowAssembler->normalizeStoredRevisionReportRow($j)
                : [
                    'project_position_id' => $j['project_position_id'] ?? null,
                    'project_fitting_id' => $j['project_fitting_id'] ?? null,
                    'material_id' => $j['material_id'] ?? null,
                    'name' => $j['name'] ?? ('Материал #' . (($j['material_id'] ?? null) ?: '—')),
                    'article' => $j['article'] ?? null,
                    'unit' => $j['unit'] ?? null,
                    'material_type' => $j['material_type'] ?? null,
                    'price_per_unit' => $j['price_per_unit'] ?? null,
                    'currency' => $j['currency'] ?? 'RUB',
                    'source_url' => $j['source_url'] ?? null,
                    'observed_at' => $j['observed_at'] ?? null,
                    'screenshot_path' => $j['screenshot_path'] ?? null,
                    'true_score' => $j['true_score'] ?? null,
                    'source_type' => $j['source_type'] ?? null,
                    'capture_source' => $j['capture_source'] ?? null,
                    'cost_driver_type' => $j['cost_driver_type'] ?? null,
                    'source_domain' => $j['source_domain'] ?? null,
                    'reference_type' => $j['reference_type'] ?? null,
                    'finished_product_specification_id' => $j['finished_product_specification_id'] ?? null,
                    'specification_name' => $j['specification_name'] ?? null,
                    'facade_characteristics' => is_array($j['facade_characteristics'] ?? null) ? $j['facade_characteristics'] : [],
                    'pricing_basis' => is_array($j['pricing_basis'] ?? null) ? $j['pricing_basis'] : [],
                    'position_summary' => is_array($j['position_summary'] ?? null) ? $j['position_summary'] : [],
                    'basis_note' => $j['basis_note'] ?? null,
                    'source_level_snapshot' => is_array($j['source_level_snapshot'] ?? null) ? $j['source_level_snapshot'] : [],
                ];
            $facadeSnapshotPresentation = ($normalized['reference_type'] ?? null) === 'snapshot_summary'
                ? $this->finishedProductFacadeSnapshotPresenter->presentFromJustificationSummary($normalized)
                : null;

            return [
                ...$normalized,
                'facade_snapshot_presentation' => $facadeSnapshotPresentation,
            ];
        })->values()->all();

        $evidenceSummary = is_array($snapshot['evidence_summary'] ?? null)
            ? $snapshot['evidence_summary']
            : null;
        if (is_array($evidenceSummary)) {
            $evidenceSummary['missing_items'] = $this->resolveMissingEvidenceItemsForPdf($project, $snapshot, $evidenceSummary);
        }

        $pdf = Pdf::loadView('reports.price_justification', [
            'project' => $project,
            'revision' => $revision,
            'rows' => $rows,
            'evidenceSummary' => $evidenceSummary,
            'reportSettings' => $reportSettings,
        ])
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));

        $rawFilename = "price_justification_{$project->number}_rev_{$revision->number}.pdf";
        $filename = preg_replace('#[\\/:*?"<>|]#', '_', $rawFilename);

        $this->recordUsageEvent(BillingCodes::METRIC_PDF_PRICE_JUSTIFICATION_GENERATED, 1, [
            'project' => $project,
            'feature_code' => BillingCodes::FEATURE_PDF_PRICE_JUSTIFICATION,
            'subject_type' => ProjectRevision::class,
            'subject_id' => $revision->id,
            'unit' => 'count',
            'source' => 'api',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
                'revision_number' => $revision->number,
            ],
        ]);

        return $pdf->download($filename);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $evidenceSummary
     * @return array<int, array<string, mixed>>
     */
    private function resolveMissingEvidenceItemsForPdf(Project $project, array $snapshot, array $evidenceSummary): array
    {
        $snapshotMissing = $evidenceSummary['missing_items'] ?? $evidenceSummary['missing'] ?? null;
        if (is_array($snapshotMissing) && $snapshotMissing !== []) {
            return array_values($snapshotMissing);
        }

        $runId = (int) ($snapshot['revision_run_id'] ?? 0);
        if ($runId <= 0) {
            return [];
        }

        $run = RevisionRun::query()
            ->with([
                'items.position.material',
                'items.position.edgeMaterial',
                'items.position.facadeMaterial',
                'items.projectFitting.material',
                'items.material',
                'items.priceHistory',
                'items.evidenceSubject',
            ])
            ->where('project_id', $project->id)
            ->find($runId);

        if (!$run) {
            return [];
        }

        return $run->items
            ->reject(fn (RevisionRunItem $item) => $item->isCompleted())
            ->map(fn (RevisionRunItem $item) => [
                'name' => $this->missingEvidenceItemName($item),
                'component' => $item->cost_driver_type,
                'unit' => $this->missingEvidenceItemUnit($item),
                'estimate_price' => $this->missingEvidenceItemPrice($item),
                'reasons' => $this->missingEvidenceReasons($item),
            ])
            ->values()
            ->all();
    }

    private function missingEvidenceItemName(RevisionRunItem $item): string
    {
        $subject = $item->evidenceSubject;

        return $subject?->name
            ?? $subject?->title
            ?? $item->material?->name
            ?? $item->projectFitting?->name
            ?? $item->projectFitting?->material?->name
            ?? $item->position?->facadeMaterial?->name
            ?? $item->position?->material?->name
            ?? $item->position?->edgeMaterial?->name
            ?? ('Позиция #' . ($item->project_position_id ?: $item->project_fitting_id ?: $item->id));
    }

    private function missingEvidenceItemUnit(RevisionRunItem $item): ?string
    {
        $subject = $item->evidenceSubject;

        return $subject?->unit
            ?? $item->material?->unit
            ?? $item->projectFitting?->unit
            ?? $item->projectFitting?->material?->unit
            ?? $item->position?->facadeMaterial?->unit
            ?? $item->position?->material?->unit
            ?? $item->position?->edgeMaterial?->unit;
    }

    private function missingEvidenceItemPrice(RevisionRunItem $item): mixed
    {
        $subject = $item->evidenceSubject;

        return $item->priceHistory?->price_per_unit
            ?? $subject?->price
            ?? $subject?->cost
            ?? $item->material?->price
            ?? $item->projectFitting?->price
            ?? $item->projectFitting?->material?->price
            ?? $item->position?->facadeMaterial?->price
            ?? $item->position?->material?->price
            ?? $item->position?->edgeMaterial?->price;
    }

    /**
     * @return array<int, string>
     */
    private function missingEvidenceReasons(RevisionRunItem $item): array
    {
        $diagnosticReasons = data_get($item->diagnostics_json, 'evidence_coverage.reasons');
        if (is_array($diagnosticReasons) && $diagnosticReasons !== []) {
            return array_values($diagnosticReasons);
        }

        if (!$item->source_url && !in_array($item->cost_driver_type, ['operation', 'labor_work', 'expense'], true)) {
            return ['no_source_url'];
        }

        return match ($item->status) {
            RevisionRunItem::STATUS_PARSE_ERROR => ['parse_failed'],
            RevisionRunItem::STATUS_TIMEOUT => ['source_unavailable'],
            RevisionRunItem::STATUS_NO_TEMPLATE,
            RevisionRunItem::STATUS_BLOCKED,
            RevisionRunItem::STATUS_NEEDS_MANUAL,
            RevisionRunItem::STATUS_PENDING => ['no_evidence_record'],
            default => ['no_evidence_record'],
        };
    }

    private function generatePublicId(): string
    {
        do {
            $id = Str::lower(Str::random(10));
        } while (RevisionPublication::where('public_id', $id)->exists());

        return $id;
    }

    private function decodeRevisionSnapshot(ProjectRevision $revision): ?array
    {
        $snapshotRaw = $revision->getRawOriginal('snapshot_json');

        if (is_array($snapshotRaw)) {
            return $snapshotRaw;
        }

        if (!is_string($snapshotRaw)) {
            return null;
        }

        $snapshot = json_decode($snapshotRaw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($snapshot)) {
            return $snapshot;
        }

        if (json_last_error() === JSON_ERROR_UTF8 && function_exists('mb_convert_encoding')) {
            $normalized = mb_convert_encoding($snapshotRaw, 'UTF-8', 'UTF-8');
            $snapshot = json_decode($normalized, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($snapshot)) {
                return $snapshot;
            }
        }

        return null;
    }

    private function getPublicUrlForRevision(ProjectRevision $revision): ?string
    {
        $publication = RevisionPublication::where('project_revision_id', $revision->id)
            ->orderByDesc('created_at')
            ->first();

        if (!$publication) {
            $publication = RevisionPublication::create([
                'project_revision_id' => $revision->id,
                'public_id' => $this->generatePublicId(),
                'is_active' => true,
                'access_level' => 'public_readonly',
            ]);
        }

        if (!$publication->is_active) {
            $publication->is_active = true;
            $publication->save();
        }

        return $this->makePublicVerificationUrl($publication->public_id);
    }

    private function getDocumentTokenForRevision(ProjectRevision $revision): ?string
    {
        $publication = RevisionPublication::where('project_revision_id', $revision->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        return $publication?->public_id;
    }

    private function makeQrSvg(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'scale' => 4,
            'quietzoneSize' => 0,
            'outputBase64' => false,
        ]);

        $svg = (new QRCode($options))->render($url);
        if (!is_string($svg) || $svg === '') {
            return null;
        }

        if (str_starts_with($svg, 'data:image/svg+xml;base64,')) {
            return $svg;
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function makePublicVerificationUrl(string $publicId): string
    {
        return rtrim((string) config('app.public_verify_base_url'), '/') . "/v/{$publicId}";
    }

    private function latestActualRevision(Project $project): ?ProjectRevision
    {
        return $project->revisions()
            ->whereIn('status', ['locked', 'published'])
            ->orderByDesc('number')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionPayload(ProjectRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'number' => $revision->number,
            'status' => $revision->status,
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }
}
