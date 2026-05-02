<?php

namespace Tests\Unit\Services;

use App\Models\Material;
use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Smeta\SmetaCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CuttingPilotServiceIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cutting_pilot_uses_area_without_waste_factor_when_enabled(): void
    {
        config(['smeta.cutting_pilot_enabled' => true]);

        [$project, $operation] = $this->makeProjectWithPlateAndCuttingOperation(1.5);
        $this->makeActiveOperationPrice($operation, 120.0);

        $operations = (new SmetaCalculator())->calculateOperationData($project, 'median');
        $cutting = collect($operations)->firstWhere('id', $operation->id);

        $this->assertNotNull($cutting);
        $this->assertSame(1.0, round($cutting->quantity, 2));
        $this->assertSame(120.0, round($cutting->cost_per_unit, 2));
        $this->assertSame(120.0, round($cutting->total_cost, 2));
    }

    public function test_cutting_legacy_logic_still_uses_waste_factor_when_flag_disabled(): void
    {
        config(['smeta.cutting_pilot_enabled' => false]);

        [$project, $operation] = $this->makeProjectWithPlateAndCuttingOperation(1.5);
        $this->makeActiveOperationPrice($operation, 120.0);

        $operations = (new SmetaCalculator())->calculateOperationData($project, 'median');
        $cutting = collect($operations)->firstWhere('id', $operation->id);

        $this->assertNotNull($cutting);
        $this->assertSame(1.5, round($cutting->quantity, 2));
        $this->assertSame(120.0, round($cutting->cost_per_unit, 2));
        $this->assertSame(180.0, round($cutting->total_cost, 2));
    }

    /**
     * @return array{Project, Operation}
     */
    private function makeProjectWithPlateAndCuttingOperation(float $wasteFactor): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'CUT-PILOT-' . uniqid(),
            'expert_name' => 'Cutting Pilot Tester',
            'address' => 'Test Address',
        ]);

        $operation = Operation::create([
            'name' => 'Pilot Cutting ' . uniqid(),
            'category' => 'cutting',
            'exclusion_group' => 'cutting',
            'unit' => 'm2',
            'min_thickness' => 16.0,
            'max_thickness' => 16.0,
            'origin' => 'system',
        ]);

        $material = Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Pilot Plate ' . uniqid(),
            'article' => 'CUT-PILOT-' . uniqid(),
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'thickness' => 16.0,
            'waste_factor' => $wasteFactor,
            'price_per_unit' => 1000,
            'is_active' => true,
            'version' => 1,
            'visibility' => 'private',
            'data_origin' => 'manual',
            'trust_level' => 'unverified',
        ]);

        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $material->id,
            'quantity' => 1,
            'width' => 1000,
            'length' => 1000,
        ]);

        return [$project, $operation];
    }

    private function makeActiveOperationPrice(Operation $operation, float $price): void
    {
        $supplierOwner = User::factory()->create();
        $supplier = Supplier::create([
            'user_id' => $supplierOwner->id,
            'name' => 'Cutting Pilot Supplier ' . uniqid(),
        ]);

        $priceList = PriceList::create([
            'supplier_id' => $supplier->id,
            'name' => 'Cutting Pilot Prices ' . uniqid(),
            'type' => 'operations',
            'is_active' => true,
        ]);

        $version = PriceListVersion::create([
            'price_list_id' => $priceList->id,
            'version_number' => 1,
            'source_type' => 'file',
            'storage_disk' => 'local',
            'currency' => 'RUB',
            'status' => PriceListVersion::STATUS_ACTIVE,
        ]);

        OperationPrice::create([
            'operation_id' => $operation->id,
            'price_list_version_id' => $version->id,
            'supplier_id' => $supplier->id,
            'price_type' => OperationPrice::PRICE_TYPE_RETAIL,
            'source_price' => $price,
            'conversion_factor' => 1.0,
            'price_per_internal_unit' => $price,
            'source_unit' => 'm2',
            'source_name' => 'Cutting Pilot Test',
            'match_confidence' => 1.0,
        ]);
    }
}
