<?php

namespace App\Services;

use App\Models\ProjectPosition;
use Illuminate\Support\Collection;

class ProjectPositionBulkPolicyService
{
    public const MODE_STRICT = 'strict';
    public const MODE_PARTIAL = 'partial';

    /**
     * Resolve logical operation key from bulk payload.
     */
    public function resolveOperation(?string $action, array $updates, ?string $clearField): string
    {
        if ($action === 'delete') {
            return 'delete';
        }

        if ($clearField !== null) {
            return 'clear_' . $clearField;
        }

        if (array_key_exists('facade_material_id', $updates)) {
            return 'replace_facade_material';
        }

        if (array_key_exists('edge_scheme', $updates)) {
            return 'set_edge_scheme';
        }

        if (array_key_exists('edge_material_id', $updates)) {
            return 'replace_edge';
        }

        if (array_key_exists('material_id', $updates)) {
            return 'replace_material';
        }

        if (array_key_exists('custom_name', $updates)) {
            return 'update_custom_name';
        }

        return 'generic_update';
    }

    /**
     * @param Collection<int, ProjectPosition> $positions
     * @return array{applicable: Collection<int, ProjectPosition>, skipped: array<int, array{id:int, kind:string, reason:string}>}
     */
    public function splitApplicable(Collection $positions, string $operation): array
    {
        $applicable = collect();
        $skipped = [];

        foreach ($positions as $position) {
            $reason = $this->inapplicableReason($position, $operation);
            if ($reason === null) {
                $applicable->push($position);
                continue;
            }

            $skipped[] = [
                'id' => (int) $position->id,
                'kind' => (string) ($position->kind ?? ''),
                'reason' => $reason,
            ];
        }

        return [
            'applicable' => $applicable,
            'skipped' => $skipped,
        ];
    }

    private function inapplicableReason(ProjectPosition $position, string $operation): ?string
    {
        // Actions always available for any kind.
        if (in_array($operation, ['delete', 'update_custom_name', 'clear_custom_name', 'generic_update'], true)) {
            return null;
        }

        // Panel-only operations.
        if (in_array($operation, [
            'replace_material',
            'replace_edge',
            'set_edge_scheme',
            'clear_material_id',
            'clear_edge_material_id',
            'clear_edge_scheme',
        ], true)) {
            if ($position->kind !== ProjectPosition::KIND_PANEL) {
                return 'requires_panel';
            }

            if ($operation === 'set_edge_scheme' && empty($position->edge_material_id)) {
                return 'missing_edge_material';
            }

            return null;
        }

        // Facade-only operations.
        if (in_array($operation, [
            'replace_facade_material',
            'clear_facade_material_id',
        ], true)) {
            if ($position->kind !== ProjectPosition::KIND_FACADE) {
                return 'requires_facade';
            }
            return null;
        }

        return null;
    }
}

