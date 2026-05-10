<?php

namespace App\Services;

use App\Models\ProjectRevision;

class ProjectReportReadinessService
{
    public function __construct(
        private SnapshotService $snapshotService,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function hasMeaningfulEstimateContent(array $snapshot): bool
    {
        foreach (['positions', 'plates', 'edges', 'facades', 'fittings', 'operations', 'labor_works', 'expenses'] as $section) {
            $items = $snapshot[$section] ?? [];
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_array($item) && $this->hasMeaningfulRowValue($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function revisionHasPriceEvidence(ProjectRevision $revision): bool
    {
        $snapshot = $this->decodeRevisionSnapshot($revision);

        return is_array($snapshot)
            && (
                !empty($snapshot['price_justifications'])
                || !empty($snapshot['evidence_summary'])
            );
    }

    public function estimateSnapshotHashForRevision(ProjectRevision $revision): ?string
    {
        $snapshot = $this->decodeRevisionSnapshot($revision);
        if (is_array($snapshot) && is_string($snapshot['estimate_snapshot_hash'] ?? null)) {
            return $snapshot['estimate_snapshot_hash'];
        }

        return $this->revisionHasPriceEvidence($revision) ? null : $revision->snapshot_hash;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function comparableEvidenceHash(array $snapshot): string
    {
        $snapshot = $this->normalizeEvidenceComparableSnapshot($snapshot);

        return hash('sha256', $this->snapshotService->canonicalizeJson($snapshot));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodeRevisionSnapshot(ProjectRevision $revision): ?array
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

    /**
     * @param  array<string, mixed>  $row
     */
    private function hasMeaningfulRowValue(array $row): bool
    {
        foreach ($row as $key => $value) {
            if (is_array($value)) {
                if ($this->hasMeaningfulRowValue($value)) {
                    return true;
                }
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            if (in_array((string) $key, ['id', 'project_id', 'material_id', 'edge_material_id', 'facade_material_id', 'detail_type_id'], true)) {
                continue;
            }

            if ((float) $value > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function normalizeEvidenceComparableSnapshot(array $snapshot): array
    {
        unset($snapshot['revision_run_id']);

        if (is_array($snapshot['price_justifications'] ?? null)) {
            $snapshot['price_justifications'] = array_map(function ($row) {
                if (is_array($row) && ($row['capture_source'] ?? null) === 'internal') {
                    unset($row['observed_at']);
                }

                return $row;
            }, $snapshot['price_justifications']);
        }

        return $snapshot;
    }
}
