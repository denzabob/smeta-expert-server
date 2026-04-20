<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\Operation;
use App\Models\OperationApplicationRule;
use App\Models\OperationPrice;
use Illuminate\Database\Seeder;

class OperationApplicationRulesSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRulesForGroup(
            'cutting',
            OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2,
            10,
            'м²'
        );
        $this->seedRulesForGroup(
            'edging',
            OperationApplicationRule::QUANTITY_SOURCE_EDGE_LENGTH,
            20,
            'м.п.'
        );
        $this->seedRulesForGroup(
            'drilling',
            OperationApplicationRule::QUANTITY_SOURCE_POSITION_QUANTITY,
            30
        );
    }

    private function seedRulesForGroup(
        string $exclusionGroup,
        string $quantitySource,
        int $basePriority,
        ?string $pricingUnit = null
    ): void {
        $operations = Operation::query()
            ->whereNull('user_id')
            ->whereIn('origin', ['system', 'parser'])
            ->where('exclusion_group', $exclusionGroup)
            ->orderBy('id')
            ->get();

        if ($operations->isEmpty()) {
            $this->command?->warn("No system/parser operation found for exclusion_group={$exclusionGroup}; rule was not seeded.");

            return;
        }

        $operations->each(function (Operation $operation, int $index) use ($quantitySource, $basePriority, $pricingUnit) {
            OperationApplicationRule::updateOrCreate(
                [
                    'operation_id' => $operation->id,
                    'user_id' => null,
                    'mode' => OperationApplicationRule::MODE_AUTOMATIC,
                    'applies_to' => OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE,
                    'material_type' => Material::TYPE_PLATE,
                ],
                [
                    'quantity_source' => $quantitySource,
                    'pricing_unit' => $pricingUnit
                        ?? (OperationPrice::normalizeUnit($operation->unit) ?? 'шт.'),
                    'tariff_binding_type' => OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER,
                    'tariff_operation_id' => $operation->id,
                    'tariff_binding_json' => null,
                    'conditions_json' => $this->buildConditions($operation),
                    'quantity_config_json' => null,
                    'priority' => $basePriority + $index,
                    'is_enabled' => true,
                ],
            );
        });
    }

    private function buildConditions(Operation $operation): ?array
    {
        if ($operation->min_thickness === null && $operation->max_thickness === null) {
            return null;
        }

        return [
            'thickness' => array_filter([
                'min' => $operation->min_thickness !== null ? (float) $operation->min_thickness : null,
                'max' => $operation->max_thickness !== null ? (float) $operation->max_thickness : null,
            ], fn ($value) => $value !== null),
        ];
    }
}
