<?php

namespace App\Services;

use App\Models\FurnitureModule;

class CostCalculator
{
    public function calculate(FurnitureModule $module)
    {
        $materialsCost = 0;
        $worksCost = 0;
        $hourRate = auth()->user()->settings->get('hour_rate', 1200);

        foreach ($module->details as $detail) {
            // Material cost
            $area = ($detail->width_mm / 1000) * ($detail->height_mm / 1000);
            $materialsCost += $area * $detail->material->price_per_unit * $detail->quantity;

            // Edge cost
            if ($detail->edge_config) {
                $edgeLength = 0;
                if ($detail->edge_config['top']) {
                    $edgeLength += $detail->width_mm / 1000;
                }
                if ($detail->edge_config['bottom']) {
                    $edgeLength += $detail->width_mm / 1000;
                }
                if ($detail->edge_config['left']) {
                    $edgeLength += $detail->height_mm / 1000;
                }
                if ($detail->edge_config['right']) {
                    $edgeLength += $detail->height_mm / 1000;
                }
                // Assuming edge material is fetched somehow, for now, a placeholder
                // $materialsCost += $edgeLength * $edgeMaterial->price_per_unit * $detail->quantity;
            }

            // Fittings cost
            foreach ($detail->fittings as $fitting) {
                $materialsCost += $fitting->unit_price * $fitting->quantity;
            }

            // Works cost (placeholders)
            $worksCost += ($area * 0.1) * $hourRate; // Cutting
            // $worksCost += ($edgeLength * 0.05) * $hourRate; // Edging
            $worksCost += (count($detail->fittings) * 0.1) * $hourRate; // Drilling and assembly
        }

        $totalCost = $materialsCost + $worksCost;
        $overheads = $totalCost * 0.15;
        $finalCost = $totalCost + $overheads;

        return [
            'materials_cost' => $materialsCost,
            'works_cost' => $worksCost,
            'total_cost' => $totalCost,
            'overheads' => $overheads,
            'final_cost' => $finalCost,
        ];
    }
}
