<?php

namespace App\Services\Smeta;

use App\Models\Material;
use App\Models\OperationApplicationRule;
use App\Models\Project;
use App\Models\ProjectPosition;
use Illuminate\Support\Collection;

class OperationApplicationResolver
{
    private bool $loggedEdgingThicknessMatch = false;
    private bool $loggedEdgingThicknessSkip = false;

    /**
     * @param iterable<ProjectPosition> $positions
     * @param iterable<OperationApplicationRule> $rules
     * @return array<int, array<string, mixed>>
     */
    public function resolve(Project $project, iterable $positions, iterable $rules): array
    {
        $rows = [];
        $orderedRules = collect($rules)
            ->filter(fn (OperationApplicationRule $rule) => $this->supports($rule))
            ->sort(fn (OperationApplicationRule $a, OperationApplicationRule $b) => [
                $this->specificityRank($a),
                (int) $a->priority,
                (int) $a->id,
            ] <=> [
                $this->specificityRank($b),
                (int) $b->priority,
                (int) $b->id,
            ])
            ->values();

        foreach ($positions as $position) {
            if (!$position instanceof ProjectPosition || !$position->material) {
                continue;
            }

            $matchedExclusionGroups = [];

            foreach ($orderedRules as $rule) {
                if (!$this->matchesPosition($rule, $position)) {
                    continue;
                }

                $exclusionGroup = $rule->operation?->exclusion_group;
                if ($exclusionGroup && isset($matchedExclusionGroups[$exclusionGroup])) {
                    continue;
                }

                $quantity = $this->calculateQuantity($rule, $position);
                if ($quantity <= 0) {
                    continue;
                }

                if ($exclusionGroup) {
                    $matchedExclusionGroups[$exclusionGroup] = true;
                }

                $rows[] = [
                    'operation_id' => (int) $rule->operation_id,
                    'rule_id' => (int) $rule->id,
                    'quantity' => $quantity,
                    'quantity_source' => $rule->quantity_source,
                    'pricing_unit' => $rule->pricing_unit,
                    'tariff_binding_type' => $rule->tariff_binding_type,
                    'tariff_operation_id' => $rule->tariff_operation_id
                        ? (int) $rule->tariff_operation_id
                        : (int) $rule->operation_id,
                    'source' => 'auto',
                    'context' => [
                        'project_id' => (int) $project->id,
                        'position_id' => (int) $position->id,
                        'material_id' => (int) $position->material->id,
                        'material_type' => $position->material->type,
                        'thickness_mm' => $this->comparisonThicknessMm($rule, $position, $position->material),
                        'thickness_source' => $this->comparisonThicknessSource($rule, $position),
                        'applies_to' => $rule->applies_to,
                        'rule_material_id' => $rule->material_id,
                        'rule_material_type' => $rule->material_type,
                        'exclusion_group' => $exclusionGroup,
                        'rule_priority' => (int) $rule->priority,
                        'quantity_source' => $rule->quantity_source,
                        'pricing_unit' => $rule->pricing_unit,
                        'tariff_binding_type' => $rule->tariff_binding_type,
                        'tariff_operation_id' => $rule->tariff_operation_id
                            ? (int) $rule->tariff_operation_id
                            : (int) $rule->operation_id,
                    ],
                ];
            }
        }

        return $rows;
    }

    public function loadRulesForProject(Project $project): Collection
    {
        return OperationApplicationRule::query()
            ->with('operation')
            ->enabled()
            ->where('mode', OperationApplicationRule::MODE_AUTOMATIC)
            ->where(function ($query) use ($project) {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $project->user_id);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    private function supports(OperationApplicationRule $rule): bool
    {
        return $rule->mode === OperationApplicationRule::MODE_AUTOMATIC
            && in_array($rule->applies_to, [
                OperationApplicationRule::APPLIES_TO_MATERIAL_ID,
                OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE,
            ], true)
            && in_array($rule->quantity_source, [
                OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2,
                OperationApplicationRule::QUANTITY_SOURCE_POSITION_QUANTITY,
                OperationApplicationRule::QUANTITY_SOURCE_EDGE_LENGTH,
                OperationApplicationRule::QUANTITY_SOURCE_HOLES_COUNT,
            ], true)
            && $rule->pricing_unit !== null
            && $rule->pricing_unit !== ''
            && $rule->tariff_binding_type === OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER
            && $rule->operation !== null;
    }

    private function matchesPosition(OperationApplicationRule $rule, ProjectPosition $position): bool
    {
        $material = $position->material;

        if (!$material) {
            return false;
        }

        if ($rule->applies_to === OperationApplicationRule::APPLIES_TO_MATERIAL_ID) {
            return $rule->material_id !== null
                && (int) $material->id === (int) $rule->material_id
                && $this->matchesConditions($rule, $position, $material);
        }

        return $rule->material_type !== null
            && $material->type === $rule->material_type
            && $this->matchesConditions($rule, $position, $material);
    }

    private function specificityRank(OperationApplicationRule $rule): int
    {
        return $rule->applies_to === OperationApplicationRule::APPLIES_TO_MATERIAL_ID ? 0 : 1;
    }

    private function matchesConditions(OperationApplicationRule $rule, ProjectPosition $position, Material $material): bool
    {
        $conditions = $rule->conditions_json ?? [];
        $thicknessCondition = $conditions['thickness'] ?? null;

        if (!is_array($thicknessCondition)) {
            return true;
        }

        $thickness = $this->comparisonThicknessMm($rule, $position, $material);
        $minThickness = isset($thicknessCondition['min']) ? (float) $thicknessCondition['min'] : null;
        $maxThickness = isset($thicknessCondition['max']) ? (float) $thicknessCondition['max'] : null;

        if ($thickness === null) {
            $this->logEdgingThicknessDecision($rule, $position, null, $minThickness, $maxThickness, 'missing_thickness', false);
            return false;
        }

        if ($minThickness !== null && $thickness < $minThickness) {
            $this->logEdgingThicknessDecision($rule, $position, $thickness, $minThickness, $maxThickness, 'below_min', false);
            return false;
        }

        if ($maxThickness !== null && $thickness > $maxThickness) {
            $this->logEdgingThicknessDecision($rule, $position, $thickness, $minThickness, $maxThickness, 'above_max', false);
            return false;
        }

        $this->logEdgingThicknessDecision($rule, $position, $thickness, $minThickness, $maxThickness, 'matched', true);
        return true;
    }

    private function comparisonThicknessMm(
        OperationApplicationRule $rule,
        ProjectPosition $position,
        Material $defaultMaterial
    ): ?float {
        $material = $this->comparisonThicknessMaterial($rule, $position, $defaultMaterial);

        return $material ? $this->materialThicknessData($material)['value'] : null;
    }

    private function comparisonThicknessSource(OperationApplicationRule $rule, ProjectPosition $position): string
    {
        $material = $this->comparisonThicknessMaterial($rule, $position, $position->material);

        if (!$material) {
            return $rule->operation?->operation_kind === \App\Models\Operation::KIND_EDGING
                ? 'edge_material_missing'
                : 'material_missing';
        }

        return $this->materialThicknessData($material)['source'];
    }

    private function comparisonThicknessMaterial(
        OperationApplicationRule $rule,
        ProjectPosition $position,
        Material $defaultMaterial
    ): ?Material {
        if ($rule->operation?->operation_kind === \App\Models\Operation::KIND_EDGING) {
            return $position->edgeMaterial;
        }

        return $defaultMaterial;
    }

    private function logEdgingThicknessDecision(
        OperationApplicationRule $rule,
        ProjectPosition $position,
        ?float $actualThickness,
        ?float $minThickness,
        ?float $maxThickness,
        string $reason,
        bool $matched
    ): void {
        if ($rule->operation?->operation_kind !== \App\Models\Operation::KIND_EDGING) {
            return;
        }

        if ($matched && $this->loggedEdgingThicknessMatch) {
            return;
        }

        if (!$matched && $this->loggedEdgingThicknessSkip) {
            return;
        }

        \Log::debug('operation_application_resolver.edging_thickness_check', [
            'operation_id' => (int) $rule->operation_id,
            'operation_kind' => $rule->operation?->operation_kind,
            'position_id' => (int) $position->id,
            'edge_material_id' => $position->edge_material_id ? (int) $position->edge_material_id : null,
            'actual_thickness_value' => $actualThickness,
            'min_thickness' => $minThickness,
            'max_thickness' => $maxThickness,
            'thickness_source' => $this->comparisonThicknessSource($rule, $position),
            'skip_reason' => $reason,
        ]);

        if ($matched) {
            $this->loggedEdgingThicknessMatch = true;
            return;
        }

        $this->loggedEdgingThicknessSkip = true;
    }

    private function calculateAreaM2(ProjectPosition $position): float
    {
        $areaM2 = (($position->width ?? 0) * ($position->length ?? 0)) / 1_000_000.0;

        return $areaM2 * ($position->quantity ?? 1);
    }

    private function calculateQuantity(OperationApplicationRule $rule, ProjectPosition $position): float
    {
        return match ($rule->quantity_source) {
            OperationApplicationRule::QUANTITY_SOURCE_POSITION_QUANTITY => $this->calculatePieceQuantity($rule, $position),
            OperationApplicationRule::QUANTITY_SOURCE_EDGE_LENGTH => $this->calculateEdgeLengthQuantity($rule, $position),
            OperationApplicationRule::QUANTITY_SOURCE_HOLES_COUNT => $this->calculateHolesCountQuantity($rule, $position),
            default => $this->calculateAreaM2Quantity($rule, $position),
        };
    }

    private function calculateAreaM2Quantity(OperationApplicationRule $rule, ProjectPosition $position): float
    {
        return $this->calculateAreaM2($position) * $this->ruleMultiplier($rule);
    }

    private function calculatePieceQuantity(OperationApplicationRule $rule, ProjectPosition $position): float
    {
        return ($position->quantity ?? 1) * $this->ruleMultiplier($rule);
    }

    private function calculateEdgeLengthQuantity(OperationApplicationRule $rule, ProjectPosition $position): float
    {
        if (!$position->edge_material_id || !$position->edge_scheme || $position->edge_scheme === 'none') {
            return 0.0;
        }

        return $this->calculateEdgePerimeterMeters(
            (float) ($position->width ?? 0),
            (float) ($position->length ?? 0),
            (int) ($position->quantity ?? 0),
            (string) $position->edge_scheme,
        ) * $this->ruleMultiplier($rule);
    }

    private function calculateHolesCountQuantity(OperationApplicationRule $rule, ProjectPosition $position): float
    {
        return max(0.0, (float) ($position->quantity ?? 0)) * $this->ruleMultiplier($rule);
    }

    private function ruleMultiplier(OperationApplicationRule $rule): float
    {
        $config = $rule->quantity_config_json ?? [];

        return (float) ($config['multiplier'] ?? $config['per_piece'] ?? 1);
    }

    private function calculateEdgePerimeterMeters(
        float $widthMm,
        float $lengthMm,
        int $quantity,
        string $scheme
    ): float {
        $widthM = $widthMm / 1000;
        $lengthM = $lengthMm / 1000;

        return match ($scheme) {
            'O' => 2 * ($widthM + $lengthM) * $quantity,
            '=' => 2 * $lengthM * $quantity,
            '||' => 2 * $widthM * $quantity,
            'L' => ($widthM + $lengthM) * $quantity,
            'П' => (2 * $widthM + $lengthM) * $quantity,
            'long_one' => $lengthM * $quantity,
            'short_one' => $widthM * $quantity,
            default => 0.0,
        };
    }

    private function materialThicknessMm(Material $material): ?float
    {
        return $this->materialThicknessData($material)['value'];
    }

    /**
     * Temporary runtime fallback for edge thickness:
     * 1) thickness_mm when it is a positive number
     * 2) thickness decimal
     * 3) parsed from material name like "19x0.4" / "19х2"
     */
    private function materialThicknessData(Material $material): array
    {
        $thicknessMm = $material->thickness_mm;
        if ($thicknessMm !== null && (float) $thicknessMm > 0) {
            return [
                'value' => (float) $thicknessMm,
                'source' => 'thickness_mm',
            ];
        }

        if ($material->thickness !== null && (float) $material->thickness > 0) {
            return [
                'value' => (float) $material->thickness,
                'source' => 'thickness',
            ];
        }

        $parsedThickness = $material->type === Material::TYPE_EDGE
            ? $this->parseThicknessFromMaterialName($material->name)
            : null;

        if ($parsedThickness !== null) {
            return [
                'value' => $parsedThickness,
                'source' => 'name_parsed',
            ];
        }

        return [
            'value' => null,
            'source' => 'missing',
        ];
    }

    private function parseThicknessFromMaterialName(?string $name): ?float
    {
        if (!$name) {
            return null;
        }

        if (!preg_match('/[xх×]\s*(\d+(?:[.,]\d+)?)/u', $name, $matches)) {
            return null;
        }

        return isset($matches[1]) ? (float) str_replace(',', '.', $matches[1]) : null;
    }
}
