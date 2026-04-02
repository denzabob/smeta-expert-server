<?php

namespace App\Services;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Evidence\CaptureMethod;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\Project;
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

        $resolved = 0;
        $total = count($descriptors);

        foreach ($descriptors as $desc) {
            $isInternal = in_array($desc['cost_component'], CostComponent::internalOnlyTypes(), true);

            $status = $isInternal ? EvidenceItemStatus::RESOLVED : EvidenceItemStatus::PENDING;
            $resolutionType = $isInternal ? 'auto' : null;

            $evidenceRecordId = null;
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
                'source_url'         => $desc['source_url'] ?? null,
                'effective_value'    => $desc['effective_value'] ?? null,
                'currency'           => $desc['currency'] ?? null,
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
     * Build descriptors from the project report, one per unique cost driver.
     * Each descriptor contains everything needed to create an EstimateEvidenceItem.
     */
    private function collectDescriptors(Project $project, array $report): array
    {
        $items = [];

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
                'source_url'     => $fitting->source_url ?: ($material->source_url ?? null),
                'effective_value' => (float) $fitting->unit_price,
                'currency'       => 'RUB',
            ];
        }

        // ── Facades ──
        $facadePositions = $project->positions()
            ->where('kind', ProjectPosition::KIND_FACADE)
            ->with('facadeMaterial')
            ->get();

        foreach ($facadePositions as $pos) {
            $material = $pos->facadeMaterial;
            if (!$material) continue;
            if (isset($items['facade:' . $material->id])) continue;

            $items['facade:' . $material->id] = [
                'cost_component' => CostComponent::FACADE,
                'label'          => $material->name ?? 'Фасад #' . $material->id,
                'subject_type'   => 'project_position',
                'subject_id'     => $pos->id,
                'source_url'     => $material->source_url ?? null,
                'effective_value' => (float) ($material->price_per_unit ?? 0),
                'currency'       => 'RUB',
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

            $items['labor_work:' . $lwId] = [
                'cost_component' => CostComponent::LABOR_WORK,
                'label'          => $lw['title'] ?? 'Работа #' . $lwId,
                'subject_type'   => 'project_labor_work',
                'subject_id'     => $lwId,
                'source_url'     => null,
                'effective_value' => (float) ($lw['rate_per_hour'] ?? 0),
                'currency'       => 'RUB',
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
}
