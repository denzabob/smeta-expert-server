<?php

namespace Tests\Unit\Services;

use App\Models\Material;
use App\Models\Operation;
use App\Models\OperationApplicationRule;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\User;
use App\Services\OperationPricingSummaryService;
use App\Services\Smeta\OperationApplicationResolver;
use App\Services\Smeta\SmetaCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class OperationApplicationResolverTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        OperationApplicationRule::query()->delete();
    }

    public function test_resolver_returns_all_matching_rules_without_duplicate_exclusion_group(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $material = $this->createPlateMaterial($user);

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_PANEL,
            'material_id' => $material->id,
            'quantity' => 2,
            'width' => 1000,
            'length' => 500,
        ]);

        $primaryCutting = $this->createSystemOperation('Primary Cutting', 'cutting', 'cutting');
        $duplicateCutting = $this->createSystemOperation('Duplicate Cutting', 'cutting', 'cutting');
        $drilling = $this->createSystemOperation('Auto Drilling', 'drilling', 'drilling');

        $primaryCuttingRule = $this->createMaterialTypeRule($primaryCutting, 10);
        $duplicateCuttingRule = $this->createMaterialTypeRule($duplicateCutting, 20);
        $drillingRule = $this->createMaterialTypeRule(
            $drilling,
            30,
            quantitySource: OperationApplicationRule::QUANTITY_SOURCE_POSITION_QUANTITY,
        );

        $rows = (new OperationApplicationResolver())->resolve(
            $project,
            collect([$position->load('material')]),
            OperationApplicationRule::with('operation')->orderBy('priority')->get(),
        );

        $this->assertCount(2, $rows);
        $this->assertSame([
            $primaryCuttingRule->id,
            $drillingRule->id,
        ], array_column($rows, 'rule_id'));
        $this->assertSame([
            OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2,
            OperationApplicationRule::QUANTITY_SOURCE_POSITION_QUANTITY,
        ], array_column($rows, 'quantity_source'));
        $this->assertNotContains($duplicateCuttingRule->id, array_column($rows, 'rule_id'));
    }

    public function test_material_id_rule_has_priority_over_material_type_rule(): void
    {
        $user = User::factory()->create();
        $project = $this->createProject($user);
        $material = $this->createPlateMaterial($user);

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_PANEL,
            'material_id' => $material->id,
            'quantity' => 2,
            'width' => 1000,
            'length' => 500,
        ]);

        $typeOperation = $this->createSystemOperation('Type Cutting', 'cutting', 'cutting');
        $specificOperation = $this->createSystemOperation('Specific Cutting', 'cutting', 'cutting');

        $typeRule = $this->createMaterialTypeRule($typeOperation, 1);
        $specificRule = $this->createMaterialIdRule($specificOperation, $material, 100);

        $rows = (new OperationApplicationResolver())->resolve(
            $project,
            collect([$position->load('material')]),
            OperationApplicationRule::with('operation')->get(),
        );

        $this->assertCount(1, $rows);
        $this->assertSame($specificRule->id, $rows[0]['rule_id']);
        $this->assertNotSame($typeRule->id, $rows[0]['rule_id']);
    }


    public function test_load_rules_for_project_uses_user_and_system_scope(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->createProject($user);

        $systemRule = $this->createMaterialTypeRule(
            $this->createSystemOperation('System Cutting', 'cutting', 'cutting'),
            10,
            null,
        );
        $ownRule = $this->createMaterialTypeRule(
            $this->createSystemOperation('Own Cutting', 'cutting', 'cutting'),
            20,
            $user->id,
        );
        $otherRule = $this->createMaterialTypeRule(
            $this->createSystemOperation('Other Cutting', 'cutting', 'cutting'),
            30,
            $otherUser->id,
        );

        $ruleIds = (new OperationApplicationResolver())
            ->loadRulesForProject($project)
            ->pluck('id')
            ->all();

        $this->assertContains($systemRule->id, $ruleIds);
        $this->assertContains($ownRule->id, $ruleIds);
        $this->assertNotContains($otherRule->id, $ruleIds);
    }

    public function test_cutting_rule_adds_area_operation_and_skips_legacy_cutting(): void
    {
        config(['smeta.cutting_pilot_enabled' => false]);

        $user = User::factory()->create();
        $project = $this->createProject($user);
        $material = $this->createPlateMaterial($user);

        $legacyOperation = $this->createSystemOperation('Legacy Cutting', 'cutting', 'cutting');
        $ruleOperation = $this->createSystemOperation('Rule Cutting', 'cutting', 'cutting');

        $this->createMaterialTypeRule($ruleOperation, 10);

        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_PANEL,
            'material_id' => $material->id,
            'quantity' => 2,
            'width' => 1000,
            'length' => 500,
        ]);

        $calculator = new SmetaCalculator(
            operationPricingSummaryService: $this->mockPricingSummaryService(100.0, 'м²'),
            operationApplicationResolver: new OperationApplicationResolver(),
        );

        $operations = $calculator->calculateOperationData($project);

        $this->assertCount(1, $operations);
        $this->assertSame($ruleOperation->id, $operations[0]->id);
        $this->assertSame(1.0, $operations[0]->quantity);
        $this->assertNotSame($legacyOperation->id, $operations[0]->id);
    }

    public function test_calculator_applies_cutting_and_drilling_rules_without_legacy_cutting_duplicate(): void
    {
        config(['smeta.cutting_pilot_enabled' => false]);

        $user = User::factory()->create();
        $project = $this->createProject($user);
        $material = $this->createPlateMaterial($user);

        $legacyOperation = $this->createSystemOperation('Legacy Cutting', 'cutting', 'cutting');
        $cuttingOperation = $this->createSystemOperation('Rule Cutting', 'cutting', 'cutting');
        $drillingOperation = $this->createSystemOperation('Rule Drilling', 'drilling', 'drilling', 'шт');

        $this->createMaterialTypeRule($cuttingOperation, 10);
        $this->createMaterialTypeRule(
            $drillingOperation,
            20,
            quantitySource: OperationApplicationRule::QUANTITY_SOURCE_POSITION_QUANTITY,
        );

        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_PANEL,
            'material_id' => $material->id,
            'quantity' => 2,
            'width' => 1000,
            'length' => 500,
        ]);

        $calculator = new SmetaCalculator(
            operationPricingSummaryService: $this->mockPricingSummaryService(100.0, 'м²'),
            operationApplicationResolver: new OperationApplicationResolver(),
        );

        $operations = $calculator->calculateOperationData($project);
        $operationIds = collect($operations)->pluck('id')->all();

        $this->assertCount(2, $operations);
        $this->assertContains($cuttingOperation->id, $operationIds);
        $this->assertContains($drillingOperation->id, $operationIds);
        $this->assertNotContains($legacyOperation->id, $operationIds);
        $this->assertSame(
            1,
            collect($operations)->where('category', 'cutting')->count(),
        );
        $this->assertSame(
            1.0,
            collect($operations)->firstWhere('id', $cuttingOperation->id)->quantity,
        );
        $this->assertSame(
            2.0,
            collect($operations)->firstWhere('id', $drillingOperation->id)->quantity,
        );
    }

    public function test_calculator_marks_unit_mismatch_without_changing_price_calculation(): void
    {
        config(['smeta.cutting_pilot_enabled' => false]);

        $user = User::factory()->create();
        $project = $this->createProject($user);
        $material = $this->createPlateMaterial($user);
        $ruleOperation = $this->createSystemOperation('Rule Cutting', 'cutting', 'cutting');

        $this->createMaterialTypeRule($ruleOperation, 10);

        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_PANEL,
            'material_id' => $material->id,
            'quantity' => 2,
            'width' => 1000,
            'length' => 500,
        ]);

        $calculator = new SmetaCalculator(
            operationPricingSummaryService: $this->mockPricingSummaryService(100.0, 'шт.'),
            operationApplicationResolver: new OperationApplicationResolver(),
        );

        $operations = $calculator->calculateOperationData($project);

        $this->assertCount(1, $operations);
        $this->assertFalse($operations[0]->is_valid);
        $this->assertTrue($operations[0]->unit_mismatch);
        $this->assertSame('м²', $operations[0]->pricing_unit);
        $this->assertSame('шт.', $operations[0]->resolved_price_unit);
        $this->assertSame(100.0, $operations[0]->cost_per_unit);
        $this->assertNull($operations[0]->total_cost);
        $this->assertNull($operations[0]->toArray()['amount']);
        $this->assertTrue($operations[0]->toArray()['unit_mismatch']);
    }

    private function createProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'APP-RULE-' . uniqid(),
            'expert_name' => 'Resolver Tester',
            'address' => 'Test',
        ]);
    }

    private function createPlateMaterial(User $user): Material
    {
        return Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Rule Plate',
            'article' => 'RULE-PLATE-' . uniqid(),
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'length_mm' => 2800,
            'width_mm' => 2070,
            'thickness' => 16,
            'waste_factor' => 1.5,
        ]);
    }

    private function createSystemOperation(
        string $name,
        string $category,
        string $exclusionGroup,
        string $unit = 'м²'
    ): Operation
    {
        return Operation::create([
            'name' => $name,
            'category' => $category,
            'exclusion_group' => $exclusionGroup,
            'unit' => $unit,
            'user_id' => null,
            'origin' => 'system',
        ]);
    }

    private function createMaterialTypeRule(
        Operation $operation,
        int $priority,
        ?int $userId = null,
        string $quantitySource = OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2,
        ?array $quantityConfig = null,
    ): OperationApplicationRule
    {
        return OperationApplicationRule::create([
            'operation_id' => $operation->id,
            'user_id' => $userId,
            'mode' => OperationApplicationRule::MODE_AUTOMATIC,
            'applies_to' => OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE,
            'material_type' => Material::TYPE_PLATE,
            'quantity_source' => $quantitySource,
            'pricing_unit' => $quantitySource === OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2
                ? 'м²'
                : ($operation->unit ?: 'шт.'),
            'tariff_binding_type' => OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER,
            'tariff_operation_id' => null,
            'tariff_binding_json' => null,
            'quantity_config_json' => $quantityConfig,
            'priority' => $priority,
            'is_enabled' => true,
        ]);
    }

    private function createMaterialIdRule(
        Operation $operation,
        Material $material,
        int $priority,
        ?int $userId = null,
        string $quantitySource = OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2,
        ?array $quantityConfig = null,
    ): OperationApplicationRule {
        return OperationApplicationRule::create([
            'operation_id' => $operation->id,
            'user_id' => $userId,
            'mode' => OperationApplicationRule::MODE_AUTOMATIC,
            'applies_to' => OperationApplicationRule::APPLIES_TO_MATERIAL_ID,
            'material_type' => $material->type,
            'material_id' => $material->id,
            'quantity_source' => $quantitySource,
            'pricing_unit' => $quantitySource === OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2
                ? 'м²'
                : ($operation->unit ?: 'шт.'),
            'tariff_binding_type' => OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER,
            'tariff_operation_id' => null,
            'tariff_binding_json' => null,
            'quantity_config_json' => $quantityConfig,
            'priority' => $priority,
            'is_enabled' => true,
        ]);
    }

    private function mockPricingSummaryService(
        ?float $effectivePrice = 100.0,
        ?string $effectiveUnit = 'м²'
    ): OperationPricingSummaryService
    {
        $service = Mockery::mock(OperationPricingSummaryService::class);
        $service->shouldReceive('getSummariesForOperations')->andReturnUsing(function (array $operationIds) use ($effectivePrice, $effectiveUnit) {
            return collect($operationIds)
                ->mapWithKeys(fn ($operationId) => [
                    $operationId => [
                        'operation_id' => $operationId,
                        'resolved_source' => $effectivePrice !== null ? [
                            'key' => 'resolver:median',
                            'type' => 'resolver',
                            'id' => 'median',
                            'name' => 'Медианная цена',
                            'price' => $effectivePrice,
                            'unit' => $effectiveUnit,
                            'resolution' => 'global_median_fallback',
                        ] : null,
                        'effective_source' => $effectivePrice !== null ? [
                            'key' => 'resolver:median',
                            'type' => 'resolver',
                            'id' => 'median',
                            'name' => 'Медианная цена',
                            'price' => $effectivePrice,
                            'unit' => $effectiveUnit,
                            'mode' => 'fallback',
                        ] : null,
                        'effective_price' => $effectivePrice,
                    ],
                ])
                ->all();
        });

        return $service;
    }
}
