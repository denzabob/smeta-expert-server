<?php

namespace App\Services;

use App\Models\EstimateEvidenceRun;
use App\Models\Project;

/**
 * Builds a render-friendly view model from a finalized generic evidence run's snapshot_json.
 * Consumed by the evidence_run Blade template via DomPDF.
 */
class EstimateEvidencePdfBuilder
{
    /**
     * Build the view data array for the evidence run PDF template.
     *
     * @return array{cover: array, summary: array, items: array, exceptions: array, appendix: array}
     */
    public function build(EstimateEvidenceRun $run, Project $project): array
    {
        $snapshot = $run->snapshot_json ?? [];
        $meta = $snapshot['generation_meta'] ?? [];
        $coverage = $snapshot['evidence_coverage_summary'] ?? [];
        $rawItems = $snapshot['evidence_items'] ?? [];
        $rawRecords = $snapshot['evidence_records'] ?? [];
        $rawExceptions = $snapshot['exceptions'] ?? [];

        // Index records by id for fast lookup
        $recordsById = collect($rawRecords)->keyBy('id');

        // ── Cover ──
        $cover = [
            'project_number'  => $project->number,
            'project_name'    => $project->expert_name ?? $project->address ?? '',
            'run_uuid'        => $run->uuid,
            'finalized_at'    => $run->finalized_at?->format('d.m.Y H:i'),
            'generated_at'    => now()->format('d.m.Y H:i'),
            'total_items'     => $coverage['total_items'] ?? 0,
            'version'         => $meta['version'] ?? 'generic_v1',
        ];

        // ── Summary ──
        $byStatus = $coverage['by_status'] ?? [];
        $byComponent = $coverage['by_component'] ?? [];

        // Capture method stats derived from records
        $captureMethodStats = collect($rawRecords)
            ->groupBy(fn($r) => $r['capture_method'] ?? 'unknown')
            ->map->count()
            ->toArray();

        // Source type stats derived from records
        $sourceTypeStats = collect($rawRecords)
            ->groupBy(fn($r) => $r['source_type'] ?? 'unknown')
            ->map->count()
            ->toArray();

        $summary = [
            'total_items'          => $coverage['total_items'] ?? 0,
            'resolved'             => $byStatus['resolved'] ?? 0,
            'skipped'              => $byStatus['skipped'] ?? 0,
            'failed'               => $byStatus['failed'] ?? 0,
            'by_component'         => $byComponent,
            'by_capture_method'    => $captureMethodStats,
            'by_source_type'       => $sourceTypeStats,
        ];

        // ── Detailed items with joined record data ──
        $items = collect($rawItems)->map(function (array $item) use ($recordsById) {
            $recordId = $item['evidence_record_id'] ?? null;
            $record = $recordId ? ($recordsById[$recordId] ?? null) : null;

            $assets = $record['assets'] ?? [];
            $imageAsset = collect($assets)->first(fn($a) => str_starts_with($a['mime_type'] ?? '', 'image/'));
            $nonImageAssets = collect($assets)->filter(fn($a) => !str_starts_with($a['mime_type'] ?? '', 'image/'))->values()->all();

            return [
                'uuid'                => $item['uuid'] ?? null,
                'cost_component'      => $item['cost_component'] ?? null,
                'label'               => $item['label'] ?? null,
                'status'              => $item['status'] ?? null,
                'resolution_type'     => $item['resolution_type'] ?? null,
                'source_url'          => $item['source_url'] ?? $record['source_url'] ?? null,
                'source_domain'       => $record['source_domain'] ?? null,
                'effective_value'     => $item['effective_value'] ?? null,
                'currency'            => $item['currency'] ?? $record['currency'] ?? null,
                'observed_price'      => $record['observed_price'] ?? null,
                'extracted_name'      => $record['extracted_name'] ?? null,
                'extracted_article'   => $record['extracted_article'] ?? null,
                'capture_method'      => $record['capture_method'] ?? null,
                'source_type'         => $record['source_type'] ?? null,
                'verification_status' => $record['verification_status'] ?? null,
                'trust_score'         => $record['trust_score'] ?? null,
                'observed_at'         => $record['observed_at'] ?? null,
                'image_asset'         => $imageAsset,
                'non_image_assets'    => $nonImageAssets,
            ];
        })->toArray();

        // ── Exceptions ──
        $exceptions = collect($rawExceptions)->map(function (array $exc) {
            return [
                'uuid'           => $exc['uuid'] ?? null,
                'cost_component' => $exc['cost_component'] ?? null,
                'label'          => $exc['label'] ?? null,
                'status'         => $exc['status'] ?? null,
                'diagnostics'    => $exc['diagnostics'] ?? null,
            ];
        })->toArray();

        // ── Technical appendix (per record) ──
        $appendix = collect($rawRecords)->map(function (array $r) {
            return [
                'record_id'           => $r['id'] ?? null,
                'record_uuid'         => $r['uuid'] ?? null,
                'cost_component'      => $r['cost_component'] ?? null,
                'source_type'         => $r['source_type'] ?? null,
                'capture_method'      => $r['capture_method'] ?? null,
                'verification_status' => $r['verification_status'] ?? null,
                'trust_score'         => $r['trust_score'] ?? null,
                'created_at'          => $r['created_at'] ?? null,
                'created_by'          => $r['created_by'] ?? null,
                'assets'              => collect($r['assets'] ?? [])->map(fn($a) => [
                    'asset_uuid' => $a['uuid'] ?? null,
                    'asset_type' => $a['asset_type'] ?? null,
                    'mime_type'  => $a['mime_type'] ?? null,
                    'sha256'     => $a['sha256'] ?? null,
                ])->toArray(),
            ];
        })->toArray();

        return [
            'cover'      => $cover,
            'summary'    => $summary,
            'items'      => $items,
            'exceptions' => $exceptions,
            'appendix'   => $appendix,
        ];
    }
}
