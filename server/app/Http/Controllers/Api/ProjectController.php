<?php
// app/Http/Controllers/Api/ProjectController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\RecordsUsageEvents;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\UserSettings;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\ProjectWorkspaceAccessService;
use App\Services\Reports\ReportSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProjectController extends Controller
{
    use RecordsUsageEvents;

    public function __construct(
        private readonly ReportSettingsResolver $reportSettingsResolver,
    ) {
    }

    public function index()
    {
        $projects = Project::where('user_id', Auth::id())
            ->whereNull('archived_at')
            ->withCount(['revisions', 'positions'])
            ->with(['latestRevision' => function ($query) {
                $query->select([
                    'project_revisions.id',
                    'project_revisions.project_id',
                    'project_revisions.number',
                    'project_revisions.status',
                    'project_revisions.created_at',
                ]);
            }])
            ->get();

        $projects->each(function ($project) {
            $project->latest_revision_number = $project->latestRevision?->number;
            $project->latest_revision_status = $project->latestRevision?->status;
            $project->latest_revision_at = $project->latestRevision?->created_at;
            $project->makeHidden('latestRevision');
        });

        return $projects;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'number' => 'nullable|string|max:255',
            'expert_name' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'waste_coefficient' => 'nullable|numeric|min:0',
            'repair_coefficient' => 'nullable|numeric|min:0',
            'waste_plate_coefficient' => 'nullable|numeric|min:0',
            'waste_edge_coefficient' => 'nullable|numeric|min:0',
            'waste_operations_coefficient' => 'nullable|numeric|min:0',
            'apply_waste_to_plate' => 'nullable|boolean',
            'apply_waste_to_edge' => 'nullable|boolean',
            'apply_waste_to_operations' => 'nullable|boolean',
            'use_area_calc_mode' => 'nullable|boolean',
            'default_plate_material_id' => 'nullable|exists:materials,id',
            'default_edge_material_id' => 'nullable|exists:materials,id',
            'facade_width_allowance_mm' => 'nullable|integer|min:0|max:1000',
            'facade_height_allowance_mm' => 'nullable|integer|min:0|max:1000',
            'text_blocks' => 'nullable|array',
            'text_blocks.*.title' => 'nullable|string|max:255',
            'text_blocks.*.text' => 'nullable|string|max:10000',
            'text_blocks.*.enabled' => 'nullable|boolean',
            ...$this->reportSettingsResolver->validationRules(),
            'waste_plate_description' => 'nullable|array',
            'waste_plate_description.title' => 'nullable|string|max:255',
            'waste_plate_description.text' => 'nullable|string|max:3000',
            'show_waste_plate_description' => 'nullable|boolean',
            'waste_edge_description' => 'nullable|array',
            'waste_edge_description.title' => 'nullable|string|max:255',
            'waste_edge_description.text' => 'nullable|string|max:3000',
            'show_waste_edge_description' => 'nullable|boolean',
            'waste_operations_description' => 'nullable|array',
            'waste_operations_description.title' => 'nullable|string|max:255',
            'waste_operations_description.text' => 'nullable|string|max:3000',
            'show_waste_operations_description' => 'nullable|boolean',
            'normohour_rate' => 'nullable|numeric|min:0',
            'normohour_region' => 'nullable|string|max:255',
            'normohour_date' => 'nullable|date',
            'normohour_method' => 'nullable|in:market_vacancies,commercial_proposals,contractor_estimate,other',
            'normohour_justification' => 'nullable|string|max:5000',
            'price_confirmation_freshness_days' => 'nullable|integer|min:1|max:365',
        ], $this->projectValidationMessages());

        // Получить пользовательские настройки (или создать если нет)
        $userSettings = Auth::user()->settings()->firstOrCreate(
            ['user_id' => Auth::id()],
            UserSettings::defaultAttributes(),
        );

        // Применить дефолты из user_settings если они не переданы явно
        $defaults = [
            'number' => $request->input('number') ?? $userSettings->default_number ?? 'Новый проект',
            'expert_name' => $request->input('expert_name') ?? $userSettings->default_expert_name ?? '',
            'address' => $request->input('address') ?? '',
            'region_id' => $userSettings->region_id,
            'waste_coefficient' => $request->input('waste_coefficient') ?? $userSettings->waste_coefficient ?? 1.0,
            'repair_coefficient' => $request->input('repair_coefficient') ?? $userSettings->repair_coefficient ?? 1.0,
            'waste_plate_coefficient' => $request->input('waste_plate_coefficient') ?? $userSettings->waste_plate_coefficient,
            'waste_edge_coefficient' => $request->input('waste_edge_coefficient') ?? $userSettings->waste_edge_coefficient,
            'waste_operations_coefficient' => $request->input('waste_operations_coefficient') ?? $userSettings->waste_operations_coefficient,
            'apply_waste_to_plate' => $request->input('apply_waste_to_plate') ?? $userSettings->apply_waste_to_plate ?? true,
            'apply_waste_to_edge' => $request->input('apply_waste_to_edge') ?? $userSettings->apply_waste_to_edge ?? true,
            'apply_waste_to_operations' => $request->input('apply_waste_to_operations') ?? $userSettings->apply_waste_to_operations ?? false,
            'use_area_calc_mode' => $request->input('use_area_calc_mode') ?? $userSettings->use_area_calc_mode ?? false,
            'default_plate_material_id' => $request->input('default_plate_material_id') ?? $userSettings->default_plate_material_id,
            'default_edge_material_id' => $request->input('default_edge_material_id') ?? $userSettings->default_edge_material_id,
            'facade_width_allowance_mm' => $request->input('facade_width_allowance_mm') ?? $userSettings->facade_width_allowance_mm ?? 0,
            'facade_height_allowance_mm' => $request->input('facade_height_allowance_mm') ?? $userSettings->facade_height_allowance_mm ?? 0,
            'text_blocks' => $this->normalizeTextBlocksForProject(
                $request->input('text_blocks') ?? $userSettings->text_blocks
            ),
            'report_settings' => $this->reportSettingsResolver->merge(
                is_array($userSettings->report_settings ?? null) ? $userSettings->report_settings : null,
                $request->input('report_settings'),
            ),
            'waste_plate_description' => $request->input('waste_plate_description') ?? $userSettings->waste_plate_description,
            'waste_edge_description' => $request->input('waste_edge_description') ?? $userSettings->waste_edge_description,
            'waste_operations_description' => $request->input('waste_operations_description') ?? $userSettings->waste_operations_description,
            'show_waste_plate_description' => $request->input('show_waste_plate_description') ?? $userSettings->show_waste_plate_description ?? false,
            'show_waste_edge_description' => $request->input('show_waste_edge_description') ?? $userSettings->show_waste_edge_description ?? false,
            'show_waste_operations_description' => $request->input('show_waste_operations_description') ?? $userSettings->show_waste_operations_description ?? false,
        ];

        // Объединить валидированные и дефолтные данные.
        // report_settings уже собраны выше как user defaults + явный override,
        // поэтому не даём сырому partial-массиву перезаписать нормализованный результат.
        $validatedForMerge = array_filter($validated, fn($value) => $value !== null);
        unset($validatedForMerge['report_settings']);
        $validated = array_merge($defaults, $validatedForMerge);
        $validated['user_id'] = Auth::id();
        $validated = $this->filterProjectAttributesForSchema($validated);

        $billingStatus = app(ProjectWorkspaceAccessService::class)->createStatus($request->user());
        $this->checkBillingGateSafely($request->user(), BillingCodes::CAP_PROJECTS_MAX_OWNED, [
            'action' => 'projects.create',
            'limit_key' => $billingStatus['limit_key'] ?? BillingCodes::CAP_PROJECTS_MAX_OWNED,
            'allowed' => $billingStatus['limit'] ?? null,
            'actual' => $billingStatus['owned_projects'] ?? 0,
            'usage' => $billingStatus['owned_projects'] ?? 0,
        ]);

        if ($billingStatus['blocked']) {
            return response()->json(
                app(ProjectWorkspaceAccessService::class)->responsePayload($billingStatus),
                423,
            );
        }
        
        try {
            $project = Project::create($validated);
        } catch (Throwable $exception) {
            Log::error('Failed to create project', [
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);

            return response()->json([
                'message' => 'Не удалось создать проект. Проверьте настройки проекта по умолчанию и повторите попытку.',
            ], 500);
        }

        $this->recordUsageEvent(BillingCodes::METRIC_PROJECTS_CREATED, 1, [
            'user' => $request->user(),
            'project' => $project,
            'feature_code' => BillingCodes::FEATURE_PROJECTS_CREATE,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'unit' => 'count',
            'source' => 'api',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
            ],
        ]);

        return response()->json($project, 201);
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);
        abort_if($project->archived_at !== null, 404);
        $project->load('positions', 'fittings', 'expenses', 'profileRates');
        
        // Manually add profileRates to ensure they're included with correct key
        $data = $project->toArray();
        $data['profileRates'] = $project->profileRates->map(function($rate) {
            return $rate->toArray();
        })->values()->all();
        $workspaceAccess = app(ProjectWorkspaceAccessService::class);
        $workspaceStatus = $workspaceAccess->editStatus($project);
        $data['billing_workspace'] = $workspaceAccess->responsePayload($workspaceStatus)['billing'];
        $data['billing_workspace']['message'] = $workspaceStatus['message'];
        
        \Log::debug('API show() method', [
            'project_id' => $project->id,
            'profile_rates_count' => count($data['profileRates']),
            'first_rate' => $data['profileRates'][0] ?? null
        ]);
        
        return response()->json($data);
    }

    public function summary(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        abort_if($project->archived_at !== null, 404);

        /** @var ProjectRevision|null $revision */
        $revision = $project->revisions()
            ->with('createdBy:id,name,email')
            ->orderByDesc('number')
            ->first();

        $snapshot = $revision ? $this->decodeRevisionSnapshot($revision) : null;
        $snapshotProject = is_array($snapshot['project'] ?? null) ? $snapshot['project'] : [];

        return response()->json([
            'project' => [
                'id' => $project->id,
                'public_id' => $project->public_id,
                'number' => $snapshotProject['number'] ?? $project->number,
                'address' => $snapshotProject['address'] ?? $project->address,
                'expert_name' => $snapshotProject['expert_name'] ?? $project->expert_name,
                'updated_at' => $project->updated_at?->toIso8601String(),
                'status' => $revision?->status,
            ],
            'latest_revision' => $revision ? [
                'id' => $revision->id,
                'number' => $revision->number,
                'status' => $revision->status,
                'created_at' => $revision->created_at?->toIso8601String(),
                'author' => $revision->createdBy?->name,
                'pdf_url' => url("/api/projects/{$project->id}/revisions/{$revision->number}/pdf"),
                'price_justification_pdf_url' => url("/api/projects/{$project->id}/revisions/{$revision->number}/price-justification.pdf"),
            ] : null,
            'totals' => $this->buildSummaryTotals($snapshot),
            'evidence' => $this->buildSummaryEvidence($project, $snapshot),
        ]);
    }


    public function update(Request $request, Project $project)
    {
        $this->authorize('update', $project);
        abort_if($project->archived_at !== null, 404);

        $validated = $request->validate([
            'number' => 'sometimes|required|string|max:255',
            'expert_name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'region_id' => 'nullable|exists:regions,id',
            'waste_coefficient' => 'sometimes|required|numeric|min:1',
            'repair_coefficient' => 'sometimes|required|numeric|min:1',
            'waste_plate_coefficient' => 'nullable|numeric|min:1',
            'waste_edge_coefficient' => 'nullable|numeric|min:1',
            'waste_operations_coefficient' => 'nullable|numeric|min:1',
            'apply_waste_to_plate' => 'sometimes|required|boolean',
            'apply_waste_to_edge' => 'sometimes|required|boolean',
            'apply_waste_to_operations' => 'sometimes|required|boolean',
            'use_area_calc_mode' => 'sometimes|required|boolean',
            'default_plate_material_id' => 'nullable|exists:materials,id',
            'default_edge_material_id' => 'nullable|exists:materials,id',
            'facade_width_allowance_mm' => 'nullable|integer|min:0|max:1000',
            'facade_height_allowance_mm' => 'nullable|integer|min:0|max:1000',
            'text_blocks' => 'nullable|array',
            'text_blocks.*.title' => 'nullable|string|max:255',
            'text_blocks.*.text' => 'nullable|string|max:10000',
            'text_blocks.*.enabled' => 'nullable|boolean',
            ...$this->reportSettingsResolver->validationRules(),
            'waste_plate_description' => 'nullable|array',
            'waste_plate_description.title' => 'nullable|string|max:255',
            'waste_plate_description.text' => 'nullable|string|max:3000',
            'show_waste_plate_description' => 'nullable|boolean',
            'waste_edge_description' => 'nullable|array',
            'waste_edge_description.title' => 'nullable|string|max:255',
            'waste_edge_description.text' => 'nullable|string|max:3000',
            'show_waste_edge_description' => 'nullable|boolean',
            'waste_operations_description' => 'nullable|array',
            'waste_operations_description.title' => 'nullable|string|max:255',
            'waste_operations_description.text' => 'nullable|string|max:3000',
            'show_waste_operations_description' => 'nullable|boolean',
            'normohour_rate' => 'nullable|numeric|min:0',
            'normohour_region' => 'nullable|string|max:255',
            'normohour_date' => 'nullable|date',
            'normohour_method' => 'nullable|in:market_vacancies,commercial_proposals,contractor_estimate,other',
            'normohour_justification' => 'nullable|string|max:5000',
            'price_confirmation_freshness_days' => 'nullable|integer|min:1|max:365',
        ], $this->projectValidationMessages());

        if (array_key_exists('report_settings', $validated)) {
            $currentReportSettings = is_array($project->report_settings ?? null)
                ? $project->report_settings
                : [];
            $validated['report_settings'] = $this->reportSettingsResolver->merge(
                $currentReportSettings,
                $validated['report_settings'],
            );
        }

        $project->update($validated);
        return $project;
    }

    private function decodeRevisionSnapshot(ProjectRevision $revision): ?array
    {
        $raw = $revision->getRawOriginal('snapshot_json');
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return is_string($decoded) ? json_decode($decoded, true) : $decoded;
        }

        return null;
    }

    private function buildSummaryTotals(?array $snapshot): array
    {
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];

        return [
            'grand_total' => $this->numberOrNull($totals['grand_total'] ?? $totals['total_amount'] ?? $totals['total'] ?? null),
            'materials_cost' => $this->numberOrNull($totals['materials_cost'] ?? null),
            'operations_cost' => $this->numberOrNull($totals['operations_cost'] ?? null),
            'fittings_cost' => $this->numberOrNull($totals['fittings_cost'] ?? null),
            'labor_works_cost' => $this->numberOrNull($totals['labor_works_cost'] ?? null),
            'expenses_cost' => $this->numberOrNull($totals['expenses_cost'] ?? null),
        ];
    }

    private function buildSummaryEvidence(Project $project, ?array $snapshot): array
    {
        $summary = is_array($snapshot['evidence_summary'] ?? null) ? $snapshot['evidence_summary'] : [];
        $justifications = is_array($snapshot['price_justifications'] ?? null) ? $snapshot['price_justifications'] : [];
        $snapshotMissing = $summary['missing_items'] ?? $summary['missing'] ?? [];
        $missing = is_array($snapshotMissing) ? $this->normalizeMissingEvidenceItems($snapshotMissing) : [];

        if ($missing === [] && is_array($snapshot) && (int) ($snapshot['revision_run_id'] ?? 0) > 0) {
            $missing = $this->loadMissingEvidenceFromRun($project, (int) $snapshot['revision_run_id']);
        }

        $total = (int) ($summary['total_items'] ?? count($justifications));
        $confirmed = (int) ($summary['confirmed_items'] ?? $summary['with_evidence'] ?? max(0, $total - count($missing)));
        $missingCount = (int) ($summary['missing_items_count'] ?? $summary['missing_count'] ?? count($missing));
        $coveragePct = $total > 0 ? round(($confirmed / $total) * 100, 1) : null;
        if (isset($summary['coverage_pct']) && is_numeric($summary['coverage_pct'])) {
            $coveragePct = round((float) $summary['coverage_pct'], 1);
        }

        return [
            'total_items' => $total,
            'confirmed_items' => $confirmed,
            'missing_items' => $missingCount,
            'coverage_pct' => $coveragePct,
            'period_from' => $summary['period_from'] ?? $this->minJustificationDate($justifications),
            'period_to' => $summary['period_to'] ?? $this->maxJustificationDate($justifications),
            'missing' => array_slice($missing, 0, 10),
        ];
    }

    private function loadMissingEvidenceFromRun(Project $project, int $runId): array
    {
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
                'section' => $this->componentLabel($item->cost_driver_type),
                'price' => $this->numberOrNull($item->priceHistory?->price_per_unit),
                'unit' => $item->priceHistory?->unit ?? $item->material?->unit ?? $item->evidenceSubject?->unit,
                'reason' => $this->reasonText($this->missingEvidenceReasons($item)),
            ])
            ->values()
            ->all();
    }

    private function normalizeMissingEvidenceItems(array $items): array
    {
        return collect($items)
            ->map(fn ($item) => is_array($item) ? [
                'name' => $item['name'] ?? $item['title'] ?? $item['position_name'] ?? 'Позиция',
                'section' => $this->componentLabel($item['section'] ?? $item['component'] ?? $item['cost_driver_type'] ?? null),
                'price' => $this->numberOrNull($item['price'] ?? $item['estimate_price'] ?? null),
                'unit' => $item['unit'] ?? null,
                'reason' => $item['reason'] ?? $this->reasonText((array) ($item['reasons'] ?? [])),
            ] : null)
            ->filter()
            ->values()
            ->all();
    }

    private function missingEvidenceItemName(RevisionRunItem $item): string
    {
        return $item->evidenceSubject?->name
            ?? $item->evidenceSubject?->title
            ?? $item->material?->name
            ?? $item->projectFitting?->name
            ?? $item->projectFitting?->material?->name
            ?? $item->position?->facadeMaterial?->name
            ?? $item->position?->material?->name
            ?? $item->position?->edgeMaterial?->name
            ?? ('Позиция #' . ($item->project_position_id ?: $item->project_fitting_id ?: $item->id));
    }

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

    private function reasonText(array $reasons): string
    {
        $labels = [
            'no_source_url' => 'нет ссылки на источник цены',
            'no_screenshot_or_document' => 'нет скриншота или документа',
            'outdated_price' => 'подтверждение цены устарело',
            'outdated_screenshot' => 'скриншот устарел',
            'price_mismatch' => 'цена в подтверждении отличается от цены в смете',
            'no_linked_material' => 'позиция не связана с материалом каталога',
            'no_evidence_record' => 'нет связанного подтверждения цены',
            'parse_failed' => 'ошибка обновления цены',
            'source_unavailable' => 'источник цены недоступен',
        ];

        $mapped = collect($reasons)
            ->filter()
            ->map(fn ($reason) => $labels[$reason] ?? (string) $reason)
            ->values()
            ->all();

        return implode('; ', $mapped) ?: 'нет связанного подтверждения цены';
    }

    private function componentLabel(?string $component): string
    {
        return [
            'plate' => 'Плита',
            'edge' => 'Кромка',
            'fitting' => 'Фурнитура',
            'facade' => 'Фасад',
            'operation' => 'Операция',
            'labor_work' => 'Работа',
            'expense' => 'Расход',
        ][$component ?? ''] ?? ($component ?: '—');
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function minJustificationDate(array $justifications): ?string
    {
        return $this->extremeJustificationDate($justifications, 'min');
    }

    private function maxJustificationDate(array $justifications): ?string
    {
        return $this->extremeJustificationDate($justifications, 'max');
    }

    private function extremeJustificationDate(array $justifications, string $mode): ?string
    {
        $timestamps = collect($justifications)
            ->map(fn ($item) => is_array($item) ? ($item['observed_at'] ?? $item['captured_at'] ?? null) : null)
            ->filter()
            ->map(fn ($value) => strtotime((string) $value))
            ->filter(fn ($value) => $value !== false)
            ->values();

        if ($timestamps->isEmpty()) {
            return null;
        }

        $timestamp = $mode === 'min' ? $timestamps->min() : $timestamps->max();

        return date('Y-m-d', (int) $timestamp);
    }

    private function projectValidationMessages(): array
    {
        return [
            'number.required' => 'Укажите номер дела.',
            'number.max' => 'Номер дела не должен превышать 255 символов.',

            'expert_name.required' => 'Укажите ФИО эксперта.',
            'expert_name.max' => 'ФИО эксперта не должно превышать 255 символов.',

            'address.required' => 'Укажите адрес объекта.',
            'address.max' => 'Адрес объекта не должен превышать 255 символов.',

            'region_id.exists' => 'Выбранный регион не найден.',

            'waste_coefficient.required' => 'Укажите коэффициент обрезков.',
            'waste_coefficient.numeric' => 'Коэффициент обрезков должен быть числом.',
            'waste_coefficient.min' => 'Коэффициент обрезков не может быть меньше 1.',

            'repair_coefficient.required' => 'Укажите ремонтный коэффициент.',
            'repair_coefficient.numeric' => 'Ремонтный коэффициент должен быть числом.',
            'repair_coefficient.min' => 'Ремонтный коэффициент не может быть меньше 1.',

            'waste_plate_coefficient.numeric' => 'Коэффициент для плитных материалов должен быть числом.',
            'waste_plate_coefficient.min' => 'Коэффициент для плитных материалов не может быть меньше 1.',

            'waste_edge_coefficient.numeric' => 'Коэффициент для кромки должен быть числом.',
            'waste_edge_coefficient.min' => 'Коэффициент для кромки не может быть меньше 1.',

            'waste_operations_coefficient.numeric' => 'Коэффициент для операций должен быть числом.',
            'waste_operations_coefficient.min' => 'Коэффициент для операций не может быть меньше 1.',

            'default_plate_material_id.exists' => 'Выбранный плитный материал не найден.',
            'default_edge_material_id.exists' => 'Выбранный кромочный материал не найден.',

            'text_blocks.array' => 'Справочные блоки переданы в неверном формате.',
            'text_blocks.*.title.max' => 'Заголовок справочного блока не должен превышать 255 символов.',
            'text_blocks.*.text.max' => 'Текст справочного блока не должен превышать 10000 символов.',

            'waste_plate_description.array' => 'Описание для плитных материалов передано в неверном формате.',
            'waste_plate_description.title.max' => 'Заголовок описания для плитных материалов не должен превышать 255 символов.',
            'waste_plate_description.text.max' => 'Описание для плитных материалов не должно превышать 3000 символов.',

            'waste_edge_description.array' => 'Описание для кромки передано в неверном формате.',
            'waste_edge_description.title.max' => 'Заголовок описания для кромки не должен превышать 255 символов.',
            'waste_edge_description.text.max' => 'Описание для кромки не должно превышать 3000 символов.',

            'waste_operations_description.array' => 'Описание для операций передано в неверном формате.',
            'waste_operations_description.title.max' => 'Заголовок описания для операций не должен превышать 255 символов.',
            'waste_operations_description.text.max' => 'Описание для операций не должно превышать 3000 символов.',

            'normohour_rate.numeric' => 'Ставка нормо-часа должна быть числом.',
            'normohour_rate.min' => 'Ставка нормо-часа не может быть отрицательной.',
            'normohour_region.max' => 'Название региона для нормо-часа не должно превышать 255 символов.',
            'normohour_date.date' => 'Дата для нормо-часа указана в неверном формате.',
            'normohour_method.in' => 'Выбран неверный метод расчёта нормо-часа.',
            'normohour_justification.max' => 'Обоснование нормо-часа не должно превышать 5000 символов.',
        ];
    }

    /**
     * @return array<int, array{title: string, text: string, enabled: bool}>
     */
    private function normalizeTextBlocksForProject(mixed $blocks): array
    {
        if (is_string($blocks)) {
            $decoded = json_decode($blocks, true);
            $blocks = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        if (!is_array($blocks)) {
            return [];
        }

        return collect($blocks)
            ->take(10)
            ->map(function ($block) {
                if (is_string($block)) {
                    return [
                        'title' => '',
                        'text' => mb_substr(trim($block), 0, 10000),
                        'enabled' => true,
                    ];
                }

                if (!is_array($block)) {
                    return null;
                }

                return [
                    'title' => mb_substr(trim((string) ($block['title'] ?? '')), 0, 255),
                    'text' => mb_substr(trim((string) ($block['text'] ?? '')), 0, 10000),
                    'enabled' => (bool) ($block['enabled'] ?? true),
                ];
            })
            ->filter(fn ($block) => is_array($block) && (($block['title'] ?? '') !== '' || ($block['text'] ?? '') !== ''))
            ->values()
            ->all();
    }

    private function filterProjectAttributesForSchema(array $attributes): array
    {
        $columns = array_flip(Schema::getColumnListing((new Project())->getTable()));

        return array_filter(
            $attributes,
            fn (string $key): bool => isset($columns[$key]),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorize('delete', $project);
        abort_if($project->archived_at !== null, 404);

        $revisionsCount = $project->revisions()->count();
        if ($revisionsCount > 0 && $request->input('confirm_delete') !== 'УДАЛИТЬ') {
            return response()->json([
                'message' => 'Для удаления проекта с ревизиями требуется подтверждение.',
                'requires_confirmation' => true,
                'confirmation_phrase' => 'УДАЛИТЬ',
                'revisions_count' => $revisionsCount,
            ], 422);
        }

        $project->archived_at = now();
        $project->save();

        $this->recordUsageEvent(BillingCodes::METRIC_PROJECTS_ARCHIVED, 1, [
            'user' => $request->user(),
            'project' => $project,
            'feature_code' => BillingCodes::FEATURE_PROJECTS_ARCHIVE,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'unit' => 'count',
            'source' => 'api',
            'metadata' => [
                'controller' => static::class,
                'action' => __FUNCTION__,
            ],
        ]);

        return response()->json(['message' => 'Проект архивирован'], 200);
    }
}
