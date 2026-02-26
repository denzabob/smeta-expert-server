<?php
// app/Services/EstimateCalculator.php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPosition;
use App\Services\AutoOperationCalculator;

class EstimateCalculator
{
    public function calculate(Project $project): array
    {
        $materials = [];
        $edges = [];
        $operations = [];

        foreach ($project->positions as $position) {
            // 1. Площадь плитного материала
            if ($position->material_id) {
                $area = ($position->width * $position->length / 1_000_000) * $position->quantity;
                // добавить в $materials
            }

            // 2. Кромка
            $edgeScheme = $position->detailType?->edge_processing ?? $position->edge_scheme;
            if ($position->edge_material_id && $edgeScheme !== 'none') {
                $lengthMm = EdgeProcessingService::calculateLength(
                    $edgeScheme,
                    $position->width,
                    $position->length
                );
                $lengthM = ($lengthMm / 1000) * $position->quantity;
                // добавить в $edges
            }

            // 3. Операции (ручные + автоматические по типу)
            // handled by AutoOperationCalculator below
        }

        // Use AutoOperationCalculator to compute operations
        $autoCalc = new AutoOperationCalculator();
        $operations = $autoCalc->calculate($project);

        return [
            'materials' => $materials,
            'edges' => $edges,
            'operations' => $operations,
            'fittings' => $this->getFittings($project),
        ];
    }
}
