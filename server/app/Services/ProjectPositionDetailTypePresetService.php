<?php

namespace App\Services;

use App\Models\DetailType;
use App\Models\Project;
use App\Models\ProjectPosition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectPositionDetailTypePresetService
{
    /**
     * @return array{position: ProjectPosition, applied: array<int, string>, skipped: array<int, string>}
     */
    public function apply(
        Project $project,
        ProjectPosition $position,
        DetailType $detailType,
        string $mode = 'missing_only'
    ): array {
        if ($mode !== 'missing_only') {
            throw ValidationException::withMessages([
                'mode' => 'Поддерживается только режим missing_only.',
            ]);
        }

        if ((int) $position->project_id !== (int) $project->id) {
            abort(404);
        }

        if (!$this->isDetailTypeAvailableForProject($detailType, $project)) {
            abort(403);
        }

        return DB::transaction(function () use ($position, $detailType): array {
            $applied = [];
            $skipped = [];

            $position->detail_type_id = $detailType->id;
            $applied[] = 'detail_type_id';

            $presetEdgeScheme = $detailType->edge_processing;
            if ($this->isMissingEdgeScheme($position->edge_scheme) && $this->hasApplicableEdgeScheme($presetEdgeScheme)) {
                $position->edge_scheme = $presetEdgeScheme;
                $applied[] = 'edge_scheme';
            } else {
                $skipped[] = 'edge_scheme';
            }

            $position->save();
            $position->load([
                'material',
                'facadeMaterial',
                'materialPrice',
                'priceQuotes.supplier',
                'finishedProductSpecification',
            ]);

            return [
                'position' => $position,
                'applied' => array_values(array_unique($applied)),
                'skipped' => array_values(array_unique($skipped)),
            ];
        });
    }

    private function isDetailTypeAvailableForProject(DetailType $detailType, Project $project): bool
    {
        return $detailType->origin === 'system'
            || (int) $detailType->user_id === (int) $project->user_id;
    }

    private function isMissingEdgeScheme(?string $edgeScheme): bool
    {
        return $edgeScheme === null || $edgeScheme === '' || $edgeScheme === 'none';
    }

    private function hasApplicableEdgeScheme(?string $edgeScheme): bool
    {
        return $edgeScheme !== null && $edgeScheme !== '' && $edgeScheme !== 'none';
    }
}
