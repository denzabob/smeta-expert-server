<?php

namespace App\Services;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\ResolutionType;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Evidence\CaptureMethod;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\ProjectPosition;
use App\Service\ReportService;
use Illuminate\Support\Str;

/**
 * Collects project cost drivers and populates EstimateEvidenceItem rows
 * for a generic evidence run.
 *
 * Mirrors the legacy RevisionRunController::collectReportItems() pattern
 * but targets the generic EstimateEvidence* models.
 */
class EvidenceRunItemCollector
{
    public function __construct(
        private ReportService $reportService,
        private UrlNormalizer $urlNormalizer,
        private MaterialConfirmationService $confirmationService,
        private FinishedProductPositionSnapshotReader $finishedProductSnapshotReader,
        private FinishedProductFacadeRevisionRowAssembler $finishedProductFacadeRevisionRowAssembler,
    ) {}

    /**
     * Create items for a run from the project's current cost drivers.
     * Internal-only items are auto-resolved with an EvidenceRecord.
     * Returns the run with updated counters.
     */
    public function populateRun(EstimateEvidenceRun $run, Project $project, int $userId): EstimateEvidenceRun
    {
        $report = $this->reportService->buildReport($project)->toArray();
        $descriptors = $this->collectDescriptors($project, $report);

        $freshnessDays = $project->price_confirmation_freshness_days
            ?? MaterialConfirmationService::DEFAULT_FRESHNESS_DAYS;

        $resolved = 0;
        $total = count($descriptors);

        foreach ($descriptors as $desc) {
            $isLaborExternal = ($desc['diagnostics_json']['labor_entry_kind'] ?? null) === 'external';
            $isInternal = !$isLaborExternal
                && in_array($desc['cost_component'], CostComponent::internalOnlyTypes(), true);

            $status = $isInternal ? EvidenceItemStatus::RESOLVED : EvidenceItemStatus::PENDING;
            $resolutionType = $isInternal ? ResolutionType::AUTO : null;

            // Normalize source_url consistently so that autoLinkToEvidenceItem()
            // can match by URL regardless of how the material URL was originally
            // stored (cleanUrl, parser, raw).
            $normalizedSourceUrl = $this->urlNormalizer->normalize($desc['source_url'] ?? null);
            $materialId = isset($desc['material_id']) ? (int) $desc['material_id'] : null;
            $diagnostics = $desc['diagnostics_json'] ?? null;
            if ($materialId) {
                $diagnostics = is_array($diagnostics) ? $diagnostics : [];
                $diagnostics['material_id'] = $materialId;
            }

            $evidenceRecordId = null;
            if (!$isInternal && !empty($desc['evidence_record_id'])) {
                $status = EvidenceItemStatus::RESOLVED;
                $resolutionType = $desc['resolution_type'] ?? ResolutionType::MANUAL;
                $evidenceRecordId = (int) $desc['evidence_record_id'];
            }

            if ($isInternal && $desc['effective_value'] !== null) {
                $record = EvidenceRecord::create([
                    'uuid'                => (string) Str::uuid(),
                    'cost_component'      => $desc['cost_component'],
                    'source_type'         => SourceType::INTERNAL_CALC,
                    'capture_method'      => CaptureMethod::AUTO_SCRAPE,
                    'verification_status' => VerificationStatus::AUTO_VERIFIED,
                    'observed_price'      => $desc['effective_value'],
                    'currency'            => $desc['currency'] ?? 'RUB',
                    'extracted_name'      => $desc['label'],
                    'trust_score'         => ($desc['cost_component'] === CostComponent::EXPENSE) ? 50 : 100,
                    'created_by'          => $userId,
                ]);
                $evidenceRecordId = $record->id;
            }

            // Freshness-based auto-resolution for external types
            if (!$isInternal && $evidenceRecordId === null) {
                $freshEvidence = $this->resolveFreshEvidenceRecord(
                    $normalizedSourceUrl,
                    $desc['cost_component'],
                    $freshnessDays,
                    $materialId,
                    $userId,
                );
                if ($freshEvidence) {
                    $freshRecord = $freshEvidence['record'];
                    $status = EvidenceItemStatus::RESOLVED;
                    $resolutionType = ResolutionType::AUTO_FRESH;
                    $evidenceRecordId = $freshRecord->id;
                    $normalizedSourceUrl = $freshEvidence['source_url'] ?? $normalizedSourceUrl;
                }
            }

            EstimateEvidenceItem::create([
                'uuid'               => (string) Str::uuid(),
                'evidence_run_id'    => $run->id,
                'cost_component'     => $desc['cost_component'],
                'label'              => $desc['label'],
                'status'             => $status,
                'resolution_type'    => $resolutionType,
                'subject_type'       => $desc['subject_type'],
                'subject_id'         => $desc['subject_id'],
                'evidence_record_id' => $evidenceRecordId,
                'source_url'         => $normalizedSourceUrl,
                'effective_value'    => $desc['effective_value'] ?? null,
                'currency'           => $desc['currency'] ?? null,
                'diagnostics_json'   => $diagnostics,
            ]);

            if ($status === EvidenceItemStatus::RESOLVED) {
                $resolved++;
            }
        }

        $run->update([
            'status'          => $total > 0 ? EvidenceRunStatus::IN_PROGRESS : EvidenceRunStatus::READY,
            'total_items'     => $total,
            'completed_items' => $resolved,
            'failed_items'    => 0,
            'started_at'      => now(),
        ]);

        return $run->fresh();
    }

    /**
     * Re-evaluate PENDING items in an existing run against current fresh proof.
     *
     * Uses the same MaterialConfirmationService::getFreshRecord() lookup as
     * creation-time auto-resolve, so the proof-selection rule is unified with
     * the "Выбрать существующее" picker's expectation.
     *
     * Returns the count of items that were auto-resolved.
     */
    public function refreshPendingItems(EstimateEvidenceRun $run, Project $project): int
    {
        $freshnessDays = $project->price_confirmation_freshness_days
            ?? MaterialConfirmationService::DEFAULT_FRESHNESS_DAYS;

        $pendingItems = $run->items()
            ->where('status', EvidenceItemStatus::PENDING)
            ->get();

        $resolved = 0;

        foreach ($pendingItems as $item) {
            $materialId = $this->resolveMaterialIdForItem($item);
            if (empty($item->source_url) && !$materialId) {
                continue;
            }

            $freshEvidence = $this->resolveFreshEvidenceRecord(
                $item->source_url,       // already normalized at creation time
                $item->cost_component,
                $freshnessDays,
                $materialId,
                $run->initiated_by ? (int) $run->initiated_by : null,
            );

            if ($freshEvidence) {
                $freshRecord = $freshEvidence['record'];
                $item->update([
                    'status'             => EvidenceItemStatus::RESOLVED,
                    'resolution_type'    => ResolutionType::AUTO_FRESH,
                    'evidence_record_id' => $freshRecord->id,
                    'source_url'         => $freshEvidence['source_url'] ?? $item->source_url,
                    'effective_value'    => $freshRecord->observed_price ?? $item->effective_value,
                    'currency'           => $freshRecord->currency ?? $item->currency,
                ]);
                $resolved++;
            }
        }

        return $resolved;
    }

    /**
     * Build descriptors from the project report, one per unique cost driver.
     * Each descriptor contains everything needed to create an EstimateEvidenceItem.
     */
    private function collectDescriptors(Project $project, array $report): array
    {
        $items = [];
        $projectLaborSources = $project->laborEvidenceSources()
            ->where('is_active', true)
            ->whereNotNull('evidence_record_id')
            ->with(['provider', 'region', 'laborProfile'])
            ->orderByDesc('labor_evidence_sources.id')
            ->get();
        $laborSourcesCount = $projectLaborSources->count();
        $baseLaborDiagnostics = [
            'labor_evidence_mode' => 'project_scoped',
            'labor_sources_count' => $laborSourcesCount,
        ];

        // ── Plates ──
        $positions = $project->positions()
            ->where('kind', ProjectPosition::KIND_PANEL)
            ->with(['material', 'edgeMaterial', 'facadeMaterial'])
            ->get();

        foreach ((array) ($report['plates'] ?? []) as $plate) {
            $materialId = (int) ($plate['id'] ?? 0);
            if ($materialId <= 0) continue;

            $position = $positions->first(fn(ProjectPosition $p) => (int) $p->material_id === $materialId);
            if (!$position) continue;

            $items['plate:' . $materialId] = [
                'cost_component' => CostComponent::PLATE,
                'label'          => $plate['name'] ?? $position->material?->name ?? 'Плита #' . $materialId,
                'subject_type'   => 'project_position',
                'subject_id'     => $position->id,
                'material_id'     => $materialId,
                'source_url'     => $plate['source_url'] ?? $position->material?->source_url ?? null,
                'effective_value' => isset($plate['price_per_m2']) ? (float) $plate['price_per_m2'] : null,
                'currency'       => 'RUB',
            ];
        }

        // ── Edges ──
        foreach ((array) ($report['edges'] ?? []) as $edge) {
            $materialId = (int) ($edge['id'] ?? 0);
            if ($materialId <= 0) continue;

            $position = $positions->first(fn(ProjectPosition $p) => (int) $p->edge_material_id === $materialId);
            if (!$position) continue;

            $items['edge:' . $materialId] = [
                'cost_component' => CostComponent::EDGE,
                'label'          => $edge['name'] ?? $position->edgeMaterial?->name ?? 'Кромка #' . $materialId,
                'subject_type'   => 'project_position',
                'subject_id'     => $position->id,
                'material_id'     => $materialId,
                'source_url'     => $edge['source_url'] ?? $position->edgeMaterial?->source_url ?? null,
                'effective_value' => isset($edge['price_per_unit']) ? (float) $edge['price_per_unit'] : null,
                'currency'       => 'RUB',
            ];
        }

        // ── Fittings ──
        $fittings = $project->fittings()->with('material')->get();
        foreach ($fittings as $fitting) {
            $material = $fitting->material;
            if (!$material) continue;

            $items['fitting:' . $fitting->id] = [
                'cost_component' => CostComponent::FITTING,
                'label'          => $fitting->name ?: $material->name,
                'subject_type'   => 'project_fitting',
                'subject_id'     => $fitting->id,
                'material_id'     => $material->id,
                'source_url'     => $fitting->source_url ?: ($material->source_url ?? null),
                'effective_value' => (float) $fitting->unit_price,
                'currency'       => 'RUB',
            ];
        }

        // ── Facades ──
        $facadePositions = $project->positions()
            ->where('kind', ProjectPosition::KIND_FACADE)
            ->with(['facadeMaterial', 'finishedProductSpecification'])
            ->get();

        foreach ($facadePositions as $pos) {
            $snapshotFacade = $this->finishedProductSnapshotReader->supports($pos)
                ? $this->finishedProductSnapshotReader->read($pos)
                : null;
            $material = $pos->facadeMaterial;

            if ($snapshotFacade === null && !$material) {
                continue;
            }

            $itemKey = $snapshotFacade !== null
                ? ('facade:finished_product_specification:' . $snapshotFacade['reference_id'])
                : ('facade:' . $material->id);

            if (isset($items[$itemKey])) {
                continue;
            }

            $items[$itemKey] = [
                'cost_component' => CostComponent::FACADE,
                'label'          => $snapshotFacade['name']
                    ?? $material?->name
                    ?? ('Фасад #' . ($snapshotFacade['reference_id'] ?? $material?->id ?? $pos->id)),
                'subject_type'   => 'project_position',
                'subject_id'     => $pos->id,
                'material_id'     => $material?->id,
                'source_url'     => $material?->source_url ?? null,
                'effective_value' => $snapshotFacade !== null
                    ? (float) ($snapshotFacade['price_per_m2'] ?? 0)
                    : (float) ($material?->price_per_unit ?? 0),
                'currency'       => 'RUB',
                'diagnostics_json' => $snapshotFacade !== null
                    ? [
                        'finished_product_specification_id' => $snapshotFacade['reference_id'] ?? null,
                        'facade_snapshot_summary' => $this->finishedProductFacadeRevisionRowAssembler->buildSnapshotSummary($pos),
                        'facade_source_level_snapshot' => $snapshotFacade['source_level_snapshot'] ?? null,
                    ]
                    : null,
            ];
        }

        // ── Operations ──
        foreach ((array) ($report['operations'] ?? []) as $op) {
            $opId = (int) ($op['id'] ?? 0);
            if ($opId <= 0 || isset($items['operation:' . $opId])) continue;

            $items['operation:' . $opId] = [
                'cost_component' => CostComponent::OPERATION,
                'label'          => $op['name'] ?? 'Операция #' . $opId,
                'subject_type'   => 'operation',
                'subject_id'     => $opId,
                'source_url'     => null,
                'effective_value' => (float) ($op['cost_per_unit'] ?? 0),
                'currency'       => 'RUB',
            ];
        }

        // ── Labor works ──
        foreach ((array) ($report['labor_works'] ?? []) as $lw) {
            $lwId = (int) ($lw['id'] ?? 0);
            if ($lwId <= 0 || isset($items['labor_work:' . $lwId])) continue;

            $diagnostics = $baseLaborDiagnostics;
            if ($laborSourcesCount === 0) {
                $diagnostics['labor_warning'] = 'No labor evidence sources attached';
            }

            $items['labor_work:' . $lwId] = [
                'cost_component' => CostComponent::LABOR_WORK,
                'label'          => $lw['title'] ?? 'Работа #' . $lwId,
                'subject_type'   => 'project_labor_work',
                'subject_id'     => $lwId,
                'source_url'     => null,
                'effective_value' => (float) ($lw['rate_per_hour'] ?? 0),
                'currency'       => 'RUB',
                'diagnostics_json' => $diagnostics,
            ];
        }

        // ── Expenses ──
        foreach ((array) ($report['expenses'] ?? []) as $exp) {
            $expId = (int) ($exp['id'] ?? 0);
            if ($expId <= 0 || isset($items['expense:' . $expId])) continue;

            $items['expense:' . $expId] = [
                'cost_component' => CostComponent::EXPENSE,
                'label'          => $exp['type'] ?? 'Расход #' . $expId,
                'subject_type'   => 'expense',
                'subject_id'     => $expId,
                'source_url'     => null,
                'effective_value' => (float) ($exp['cost'] ?? 0),
                'currency'       => 'RUB',
            ];
        }

        // ── External labor evidence ──
        // Strict project-scoped mode: only explicitly linked sources are used.
        foreach ($projectLaborSources as $source) {
            $label = $source->vacancy_title
                ?: $source->source_title
                ?: ($source->provider?->title ? 'Вакансия — ' . $source->provider->title : 'Источник труда #' . $source->id);

            $items['labor_external:' . $source->id] = [
                'cost_component' => CostComponent::LABOR_WORK,
                'label' => $label,
                'subject_type' => 'labor_evidence_source',
                'subject_id' => $source->id,
                'source_url' => $source->source_url,
                'effective_value' => $source->derived_hourly_rate !== null
                    ? (float) $source->derived_hourly_rate
                    : ($source->salary_value !== null ? (float) $source->salary_value : null),
                'currency' => $source->currency ?: 'RUB',
                'evidence_record_id' => $source->evidence_record_id,
                'resolution_type' => $source->captured_via === 'chrome'
                    ? ResolutionType::CHROME
                    : ResolutionType::MANUAL,
                'diagnostics_json' => [
                    ...$baseLaborDiagnostics,
                    'labor_entry_kind' => 'external',
                    'labor_evidence_source_id' => $source->id,
                    'provider_title' => $source->provider?->title,
                    'provider_domain' => $source->provider?->domain,
                    'employer_name' => $source->employer_name,
                    'vacancy_title' => $source->vacancy_title,
                    'vacancy_description' => $source->vacancy_description,
                    'vacancy_excerpt' => $source->vacancy_excerpt,
                    'salary_raw_text' => $source->salary_raw_text,
                    'salary_value' => $source->salary_value !== null ? (float) $source->salary_value : null,
                    'salary_value_min' => $source->salary_value_min !== null ? (float) $source->salary_value_min : null,
                    'salary_value_max' => $source->salary_value_max !== null ? (float) $source->salary_value_max : null,
                    'salary_period' => $source->salary_period,
                    'derived_hourly_rate' => $source->derived_hourly_rate !== null ? (float) $source->derived_hourly_rate : null,
                    'hours_per_month' => $source->hours_per_month,
                    'region_name' => $source->region?->name,
                    'region_id' => $source->region_id,
                    'source_title' => $source->source_title,
                    'source_date' => $source->source_date?->format('Y-m-d'),
                    'note' => $source->note,
                    'verification_status' => $source->verification_status,
                ],
            ];
        }

        // Normalize source_url using the same UrlNormalizer that auto-link uses.
        // This ensures that auto-link's WHERE source_url = $normalizedUrl can match.
        foreach ($items as &$item) {
            if (!empty($item['source_url'])) {
                $item['source_url'] = $this->urlNormalizer->normalize($item['source_url']);
            }
        }
        unset($item);

        return array_values($items);
    }

    /**
     * Resolve fresh proof first by canonical URL, then by material-bound
     * MaterialPriceHistory for legacy materials whose source_url predates Chrome capture.
     *
     * @return array{record: EvidenceRecord, source_url: string|null}|null
     */
    private function resolveFreshEvidenceRecord(
        ?string $sourceUrl,
        string $costComponent,
        int $freshnessDays,
        ?int $materialId = null,
        ?int $userId = null,
    ): ?array {
        if (!empty($sourceUrl)) {
            $record = $this->confirmationService->getFreshRecord(
                $sourceUrl,
                $costComponent,
                $freshnessDays,
            );

            if ($record) {
                return [
                    'record' => $record,
                    'source_url' => $this->urlNormalizer->normalize($record->source_url ?: $sourceUrl),
                ];
            }
        }

        if (!$materialId) {
            return null;
        }

        $since = now()->subDays($freshnessDays);

        $history = MaterialPriceHistory::query()
            ->where('material_id', $materialId)
            ->whereNotNull('evidence_record_id')
            ->where(function ($query) use ($since) {
                $query->where('observed_at', '>=', $since)
                    ->orWhere(function ($fallback) use ($since) {
                        $fallback->whereNull('observed_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->whereHas('evidenceRecord', function ($query) use ($costComponent, $userId) {
                $query->where('cost_component', $costComponent)
                    ->where('verification_status', '!=', VerificationStatus::REJECTED)
                    ->whereHas('assets', function ($assets) {
                        $assets->whereIn('asset_type', ['screenshot', 'document']);
                    });

                if ($userId !== null) {
                    $query->where('created_by', $userId);
                }
            })
            ->with('evidenceRecord')
            ->latest('id')
            ->first();

        $record = $history?->evidenceRecord;
        if (!$record) {
            return null;
        }

        if (!$this->confirmationService->isValidCandidateForItem($record, $costComponent, null, $userId)) {
            return null;
        }

        return [
            'record' => $record,
            'source_url' => $this->urlNormalizer->normalize($record->source_url ?: $history->source_url),
        ];
    }

    private function resolveMaterialIdForItem(EstimateEvidenceItem $item): ?int
    {
        $diagnosticMaterialId = (int) data_get($item->diagnostics_json, 'material_id', 0);
        if ($diagnosticMaterialId > 0) {
            return $diagnosticMaterialId;
        }

        if ($item->subject_type === 'project_position') {
            $position = ProjectPosition::find($item->subject_id);

            return match ($item->cost_component) {
                CostComponent::PLATE => $position?->material_id,
                CostComponent::EDGE => $position?->edge_material_id,
                CostComponent::FACADE => $position?->facade_material_id,
                default => null,
            };
        }

        if ($item->subject_type === 'project_fitting') {
            return ProjectFitting::find($item->subject_id)?->material_id;
        }

        return null;
    }
}
