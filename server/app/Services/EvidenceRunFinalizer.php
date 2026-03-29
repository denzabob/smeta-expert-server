<?php

namespace App\Services;

use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use Carbon\Carbon;

/**
 * Handles completeness checks and finalization of a generic evidence run.
 *
 * - canFinalize: verifies all items are in terminal statuses.
 * - finalize: persists snapshot, moves run to FINALIZED.
 */
class EvidenceRunFinalizer
{
    /**
     * Check whether a run can be finalized.
     * Returns ['ok' => true] or ['ok' => false, 'reason' => ...].
     */
    public function canFinalize(EstimateEvidenceRun $run): array
    {
        if (!in_array($run->status, EvidenceRunStatus::finalizableStatuses(), true)) {
            return [
                'ok'     => false,
                'reason' => "Run status '{$run->status}' is not finalizable. Must be one of: " .
                            implode(', ', EvidenceRunStatus::finalizableStatuses()) . '.',
            ];
        }

        $items = $run->items()->get();

        if ($items->isEmpty()) {
            return [
                'ok'     => false,
                'reason' => 'Run has no items.',
            ];
        }

        $nonTerminal = $items->filter(
            fn(EstimateEvidenceItem $item) => !in_array($item->status, EvidenceItemStatus::terminalStatuses(), true)
        );

        if ($nonTerminal->isNotEmpty()) {
            $pending = $nonTerminal->pluck('uuid')->take(5)->implode(', ');
            return [
                'ok'     => false,
                'reason' => "Run has {$nonTerminal->count()} non-terminal item(s): {$pending}.",
            ];
        }

        return ['ok' => true];
    }

    /**
     * Finalize a run: build snapshot, persist it, and mark the run as FINALIZED.
     *
     * @throws \LogicException if the run cannot be finalized.
     */
    public function finalize(EstimateEvidenceRun $run): EstimateEvidenceRun
    {
        $check = $this->canFinalize($run);
        if (!$check['ok']) {
            throw new \LogicException($check['reason']);
        }

        $snapshot = $this->buildSnapshot($run);

        $run->update([
            'status'        => EvidenceRunStatus::FINALIZED,
            'snapshot_json' => $snapshot,
            'finalized_at'  => now(),
        ]);

        return $run->fresh();
    }

    /**
     * Build the generic snapshot for a finalized run.
     *
     * Structure:
     *   evidence_coverage_summary – counts by status / cost component
     *   evidence_items            – all items with linked evidence records
     *   evidence_records          – all linked records
     *   exceptions                – items that are FAILED or SKIPPED
     *   generation_meta           – timestamp, version, user
     */
    private function buildSnapshot(EstimateEvidenceRun $run): array
    {
        $items = $run->items()->with('evidenceRecord.assets')->get();

        // ── Coverage summary ──
        $byStatus     = $items->groupBy('status')->map->count();
        $byComponent  = $items->groupBy('cost_component')->map->count();

        $coverageSummary = [
            'total_items' => $items->count(),
            'by_status'   => $byStatus->toArray(),
            'by_component' => $byComponent->toArray(),
        ];

        // ── Evidence items ──
        $evidenceItems = $items->map(function (EstimateEvidenceItem $item) {
            return [
                'uuid'               => $item->uuid,
                'cost_component'     => $item->cost_component,
                'label'              => $item->label,
                'status'             => $item->status,
                'resolution_type'    => $item->resolution_type,
                'subject_type'       => $item->subject_type,
                'subject_id'         => $item->subject_id,
                'evidence_record_id' => $item->evidence_record_id,
                'source_url'         => $item->source_url,
                'effective_value'    => $item->effective_value,
                'currency'           => $item->currency,
            ];
        })->values()->toArray();

        // ── Evidence records (deduplicated, enriched for PDF) ──
        $records = $items
            ->pluck('evidenceRecord')
            ->filter()
            ->unique('id')
            ->map(function ($record) {
                $assets = $record->assets->map(fn($a) => [
                    'uuid'      => $a->uuid,
                    'asset_type' => $a->asset_type,
                    'file_path'  => $a->file_path,
                    'mime_type'  => $a->mime_type,
                    'sha256'     => $a->sha256,
                ])->values()->toArray();

                return [
                    'id'                  => $record->id,
                    'uuid'                => $record->uuid,
                    'cost_component'      => $record->cost_component,
                    'source_type'         => $record->source_type,
                    'capture_method'      => $record->capture_method,
                    'observed_price'      => $record->observed_price,
                    'currency'            => $record->currency,
                    'extracted_name'      => $record->extracted_name,
                    'extracted_article'   => $record->extracted_article,
                    'source_url'          => $record->source_url,
                    'source_domain'       => $record->source_domain,
                    'observed_at'         => $record->observed_at?->toIso8601String(),
                    'verification_status' => $record->verification_status,
                    'trust_score'         => $record->trust_score,
                    'metadata_json'       => $record->metadata_json,
                    'created_at'          => $record->created_at?->toIso8601String(),
                    'created_by'          => $record->created_by,
                    'assets'              => $assets,
                ];
            })->values()->toArray();

        // ── Exceptions ──
        $exceptions = $items
            ->filter(fn($item) => in_array($item->status, [EvidenceItemStatus::FAILED, EvidenceItemStatus::SKIPPED], true))
            ->map(fn($item) => [
                'uuid'           => $item->uuid,
                'cost_component' => $item->cost_component,
                'label'          => $item->label,
                'status'         => $item->status,
                'diagnostics'    => $item->diagnostics_json,
            ])->values()->toArray();

        // ── Generation meta ──
        $generationMeta = [
            'finalized_at' => Carbon::now()->toIso8601String(),
            'version'      => 'generic_v1',
            'initiated_by' => $run->initiated_by,
        ];

        return [
            'evidence_coverage_summary' => $coverageSummary,
            'evidence_items'            => $evidenceItems,
            'evidence_records'          => $records,
            'exceptions'                => $exceptions,
            'generation_meta'           => $generationMeta,
        ];
    }
}
