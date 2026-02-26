<?php
namespace App\Services;

use App\Models\Project;
use App\Models\Operation;
use App\Models\Material;

class AutoOperationCalculator
{
    public function calculate(Project $project): array
    {
        $detailOps = [];
        $materialOps = [];
        $edgeOps = [];

        // 1) Collect DetailType operations
        foreach ($project->positions as $pos) {
            $dt = $pos->detailType;
            if (! $dt) continue;
            $dtOps = $dt->detailTypeOperations()->with('operation')->get();
            foreach ($dtOps as $dto) {
                $op = $dto->operation;
                if (! $op) continue;
                $qty = $this->evaluateFormula($dto->quantity_formula, $pos) * ($pos->quantity ?? 1);
                $key = $op->id;
                if (! isset($detailOps[$key])) {
                    $detailOps[$key] = [
                        'operation_id' => $op->id,
                        'name' => $op->name,
                        'category' => $op->category,
                        'unit' => $op->unit,
                        'cost_per_unit' => (float) $op->cost_per_unit,
                        'quantity' => 0.0,
                        'source' => 'detail_type',
                        'exclusion_group' => $op->exclusion_group ?? null,
                    ];
                }
                $detailOps[$key]['quantity'] += $qty;
            }
        }

        // 2) Collect material (cutting) operations
        foreach ($project->positions as $pos) {
            if (empty($pos->material_id)) continue;
            $mat = Material::find($pos->material_id);
            if (! $mat) continue;
            $thickness = $mat->thickness;
            $waste = $mat->waste_factor ?? 1.0;
            $area_m2 = (($pos->width ?? 0) * ($pos->length ?? 0)) / 1000000.0;
            $qty = $area_m2 * $waste * ($pos->quantity ?? 1);

            $query = Operation::where('exclusion_group', 'cutting');
            if ($thickness !== null) {
                $query->where(function ($q) use ($thickness) {
                    $q->whereNull('min_thickness')->orWhere('min_thickness', '<=', $thickness);
                })->where(function ($q) use ($thickness) {
                    $q->whereNull('max_thickness')->orWhere('max_thickness', '>=', $thickness);
                });
            }
            $op = $query->orderByRaw('COALESCE(max_thickness, 9999) - COALESCE(min_thickness, 0) ASC')->first();
            if ($op) {
                $key = $op->id;
                if (! isset($materialOps[$key])) {
                    $materialOps[$key] = [
                        'operation_id' => $op->id,
                        'name' => $op->name,
                        'category' => $op->category,
                        'unit' => $op->unit,
                        'cost_per_unit' => (float) $op->cost_per_unit,
                        'quantity' => 0.0,
                        'source' => 'material',
                        'exclusion_group' => $op->exclusion_group ?? null,
                    ];
                }
                $materialOps[$key]['quantity'] += $qty;
            }
        }

        // 3) Collect edging operations
        foreach ($project->positions as $pos) {
            if (empty($pos->edge_material_id) || empty($pos->edge_scheme) || $pos->edge_scheme === 'none') continue;
            $edgeMat = Material::find($pos->edge_material_id);
            if (! $edgeMat) continue;
            $thickness = $edgeMat->thickness;
            $waste = $edgeMat->waste_factor ?? 1.0;

            $W = floatval($pos->width ?? 0);
            $L = floatval($pos->length ?? 0);
            $len_mm = \App\Services\EdgeProcessingService::calculateLength($pos->edge_scheme, $W, $L);
            $len_m = ($len_mm / 1000.0) * ($pos->quantity ?? 1);
            $qty = $len_m * $waste;

            $query = Operation::where('exclusion_group', 'edging');
            if ($thickness !== null) {
                $query->where(function ($q) use ($thickness) {
                    $q->whereNull('min_thickness')->orWhere('min_thickness', '<=', $thickness);
                })->where(function ($q) use ($thickness) {
                    $q->whereNull('max_thickness')->orWhere('max_thickness', '>=', $thickness);
                });
            }
            $op = $query->orderByRaw('COALESCE(max_thickness, 9999) - COALESCE(min_thickness, 0) ASC')->first();
            if ($op) {
                $key = $op->id;
                if (! isset($edgeOps[$key])) {
                    $edgeOps[$key] = [
                        'operation_id' => $op->id,
                        'name' => $op->name,
                        'category' => $op->category,
                        'unit' => $op->unit,
                        'cost_per_unit' => (float) $op->cost_per_unit,
                        'quantity' => 0.0,
                        'source' => 'edge',
                        'exclusion_group' => $op->exclusion_group ?? null,
                    ];
                }
                $edgeOps[$key]['quantity'] += $qty;
            }
        }

        // 4) Exclusion: if material/edge ops define exclusion_group, remove detailType ops with same group
        $excludeGroups = [];
        foreach (array_merge($materialOps, $edgeOps) as $mop) {
            if (!empty($mop['exclusion_group'])) $excludeGroups[] = $mop['exclusion_group'];
        }
        if (!empty($excludeGroups)) {
            foreach ($detailOps as $k => $d) {
                if (!empty($d['exclusion_group']) && in_array($d['exclusion_group'], $excludeGroups)) {
                    unset($detailOps[$k]);
                }
            }
        }

        // 5) Merge results: detailOps + materialOps + edgeOps
        $all = [];
        foreach (array_merge($detailOps, $materialOps, $edgeOps) as $entry) {
            $id = $entry['operation_id'];
            if (! isset($all[$id])) {
                $all[$id] = $entry;
            } else {
                $all[$id]['quantity'] += $entry['quantity'];
            }
        }

        // 6) Add manual operations from project manualOperations (DB table)
        $manuals = $project->manualOperations()->with('operation')->get();
        foreach ($manuals as $m) {
            $id = 'manual_'.$m->id;
            $all[$id] = [
                'operation_id' => $m->operation_id,
                'name' => $m->operation->name ?? '',
                'category' => $m->operation->category ?? '',
                'unit' => $m->operation->unit ?? '',
                'cost_per_unit' => (float) ($m->operation->cost_per_unit ?? 0),
                'quantity' => (float) $m->quantity,
                'source' => 'manual',
                'project_manual_operation_id' => $m->id,
            ];
        }

        return array_values($all);
    }

    private function evaluateFormula(string $formula, $position)
    {
        switch (trim($formula)) {
            case 'perimeter_m':
                return (($position->width + $position->length) * 2) / 1000.0;
            case 'area_m2':
                return ($position->width * $position->length) / 1000000.0;
            default:
                if (is_numeric($formula)) return (float) $formula;
                return 0.0;
        }
    }
}
