<?php

namespace Tests\Unit\Services;

use App\Models\Operation;
use App\Models\OperationApplicationRule;
use App\Models\OperationPrice;
use App\Models\OperationPriceSource;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Project;
use App\Models\ProjectManualOperation;
use App\Models\ProjectPosition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Smeta\SmetaCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SmetaCalculatorPricingSummaryIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_active_operation_price_source_changes_operation_price_in_smeta(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $operation = $this->makeUserOperation($user, 'м²');

        ProjectManualOperation::create([
            'project_id' => $project->id,
            'operation_id' => $operation->id,
            'quantity' => 2,
        ]);

        [$supplier, $version] = $this->makeSupplierVersion($user);
        $this->makeOperationPrice($operation, $supplier, $version, 120.0, 'м²');

        OperationPriceSource::create([
            'operation_id' => $operation->id,
            'type' => OperationPriceSource::TYPE_MANUAL,
            'value' => 250.0,
            'unit' => 'м²',
            'source_name' => 'Manual active source',
            'document_ref' => null,
            'is_active' => true,
            'created_at' => now(),
        ]);

        $operations = (new SmetaCalculator())->calculateOperationData($project);
        $resolved = collect($operations)->firstWhere('id', $operation->id);

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is_valid);
        $this->assertSame(250.0, $resolved->cost_per_unit);
        $this->assertSame(500.0, $resolved->total_cost);
        $this->assertSame('м²', $resolved->resolved_price_unit);
    }

    public function test_no_effective_price_marks_operation_invalid(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $operation = $this->makeUserOperation($user, 'шт.');

        ProjectManualOperation::create([
            'project_id' => $project->id,
            'operation_id' => $operation->id,
            'quantity' => 3,
        ]);

        $operations = (new SmetaCalculator())->calculateOperationData($project);
        $resolved = collect($operations)->firstWhere('id', $operation->id);

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->is_valid);
        $this->assertFalse($resolved->unit_mismatch);
        $this->assertSame(0.0, $resolved->cost_per_unit);
        $this->assertNull($resolved->total_cost);
        $this->assertNull($resolved->resolved_price_unit);
    }

    public function test_unit_mismatch_still_invalidates_operation_when_summary_unit_differs_from_rule_unit(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makePlateMaterial($user);
        $operation = $this->makeSystemOperation('шт.');

        OperationApplicationRule::create([
            'operation_id' => $operation->id,
            'user_id' => $user->id,
            'mode' => OperationApplicationRule::MODE_AUTOMATIC,
            'applies_to' => OperationApplicationRule::APPLIES_TO_MATERIAL_TYPE,
            'material_type' => $material->type,
            'material_id' => null,
            'quantity_source' => OperationApplicationRule::QUANTITY_SOURCE_POSITION_AREA_M2,
            'pricing_unit' => 'м²',
            'tariff_binding_type' => OperationApplicationRule::TARIFF_BINDING_OPERATION_RESOLVER,
            'tariff_operation_id' => null,
            'tariff_binding_json' => null,
            'quantity_config_json' => null,
            'priority' => 10,
            'is_enabled' => true,
        ]);

        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_PANEL,
            'material_id' => $material->id,
            'quantity' => 2,
            'width' => 1000,
            'length' => 500,
        ]);

        [$supplier, $version] = $this->makeSupplierVersion($user);
        $this->makeOperationPrice($operation, $supplier, $version, 100.0, 'шт.');

        $operations = (new SmetaCalculator())->calculateOperationData($project);
        $resolved = collect($operations)->firstWhere('id', $operation->id);

        $this->assertNotNull($resolved);
        $this->assertFalse($resolved->is_valid);
        $this->assertTrue($resolved->unit_mismatch);
        $this->assertSame('м²', $resolved->pricing_unit);
        $this->assertSame('шт.', $resolved->resolved_price_unit);
        $this->assertSame(100.0, $resolved->cost_per_unit);
        $this->assertNull($resolved->total_cost);
    }

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'SMETA-PRICE-' . uniqid(),
            'expert_name' => 'Summary Integration Tester',
            'address' => 'Test',
        ]);
    }

    private function makeUserOperation(User $user, string $unit): Operation
    {
        return Operation::create([
            'name' => 'Summary Calc Op ' . uniqid(),
            'category' => 'other',
            'operation_kind' => 'other',
            'unit' => $unit,
            'user_id' => $user->id,
            'origin' => 'user',
        ]);
    }

    private function makeSystemOperation(string $unit): Operation
    {
        return Operation::create([
            'name' => 'Auto Rule Op ' . uniqid(),
            'category' => 'cutting',
            'operation_kind' => 'cutting',
            'exclusion_group' => 'cutting',
            'unit' => $unit,
            'origin' => 'system',
        ]);
    }

    private function makePlateMaterial(User $user)
    {
        return \App\Models\Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Rule Plate ' . uniqid(),
            'article' => 'RULE-' . uniqid(),
            'type' => \App\Models\Material::TYPE_PLATE,
            'unit' => 'м²',
            'thickness' => 16.0,
            'waste_factor' => 1.0,
            'price_per_unit' => 1000,
            'is_active' => true,
            'version' => 1,
            'visibility' => 'private',
            'data_origin' => 'manual',
            'trust_level' => 'unverified',
        ]);
    }

    private function makeSupplierVersion(User $user): array
    {
        $supplier = Supplier::create([
            'user_id' => $user->id,
            'name' => 'Calc Summary Supplier ' . uniqid(),
        ]);

        $priceList = PriceList::create([
            'supplier_id' => $supplier->id,
            'name' => 'Calc Summary List ' . uniqid(),
            'type' => 'operations',
            'is_active' => true,
        ]);

        $version = PriceListVersion::create([
            'price_list_id' => $priceList->id,
            'version_number' => 1,
            'source_type' => PriceListVersion::SOURCE_FILE,
            'storage_disk' => 'local',
            'currency' => 'RUB',
            'status' => PriceListVersion::STATUS_ACTIVE,
        ]);

        return [$supplier, $version];
    }

    private function makeOperationPrice(
        Operation $operation,
        Supplier $supplier,
        PriceListVersion $version,
        float $price,
        string $sourceUnit
    ): OperationPrice {
        return OperationPrice::create([
            'supplier_id' => $supplier->id,
            'price_list_version_id' => $version->id,
            'operation_id' => $operation->id,
            'price_type' => OperationPrice::PRICE_TYPE_RETAIL,
            'source_price' => $price,
            'conversion_factor' => 1.0,
            'price_per_internal_unit' => $price,
            'source_unit' => $sourceUnit,
            'source_name' => 'Calc Summary Import ' . uniqid(),
            'match_confidence' => 1.0,
        ]);
    }

}
