<?php

namespace Tests\Unit\Services;

use App\Models\Operation;
use App\Models\OperationPrice;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PriceImport\PriceImportExecutorV2;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use ReflectionClass;
use Tests\TestCase;

/**
 * Block 2: Verify that operation import predicate fields (min_thickness,
 * max_thickness, exclusion_group) are persisted via PriceImportExecutorV2.
 *
 * Tests call saveOperationPrice() via reflection to isolate the persistence
 * layer from session/queue/executor orchestration.
 */
class PriceImportExecutorV2PredicatePersistenceTest extends TestCase
{
    use DatabaseTransactions;

    private PriceImportExecutorV2 $executor;
    private \ReflectionMethod $saveMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = new PriceImportExecutorV2();
        $this->saveMethod = (new ReflectionClass($this->executor))
            ->getMethod('saveOperationPrice');
        $this->saveMethod->setAccessible(true);
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makeSupplier(int $userId): Supplier
    {
        return Supplier::create([
            'user_id' => $userId,
            'name' => 'Test Supplier ' . uniqid(),
        ]);
    }

    /**
     * Build a PriceListVersion through the required PriceList parent.
     * price_list_versions.price_list_id is NOT NULL (FK to price_lists).
     */
    private function makeVersion(int $supplierId): PriceListVersion
    {
        $priceList = PriceList::create([
            'supplier_id' => $supplierId,
            'name' => 'Test List ' . uniqid(),
            'type' => 'operations',
            'is_active' => true,
        ]);

        return PriceListVersion::create([
            'price_list_id' => $priceList->id,
            'version_number' => 1,
            'source_type' => 'file',
            'storage_disk' => 'local',
            'currency' => 'RUB',
            'status' => PriceListVersion::STATUS_ACTIVE,
        ]);
    }

    private function makeOperation(): Operation
    {
        return Operation::create([
            'name' => 'Тест операция ' . uniqid(),
            'category' => 'Работы',
            'unit' => 'м²',
        ]);
    }

    /**
     * Invoke saveOperationPrice() via reflection.
     *
     * The method takes $result by reference as its 9th parameter.
     * ReflectionMethod::invoke() does not support by-reference arguments;
     * we use invokeArgs() with a reference planted in the args array.
     */
    private function invokeSave(
        ?int $operationId,
        int $supplierId,
        int $versionId,
        float $price,
        array $rawData
    ): void {
        $result = [];
        $args = [
            $operationId,
            $supplierId,
            $versionId,
            $price,
            'м²',
            'RUB',
            $rawData,
            ['row_index' => 1, 'match_confidence' => null],
            &$result,
        ];
        $this->saveMethod->invokeArgs($this->executor, $args);
    }

    // -----------------------------------------------------------------------
    // Create path
    // -----------------------------------------------------------------------

    public function test_create_persists_all_three_predicate_fields(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);
        $operation = $this->makeOperation();

        $this->invokeSave($operation->id, $supplier->id, $version->id, 150.0, [
            'name' => 'Распиловка ДСП',
            'cost_per_unit' => 150,
            'unit' => 'м²',
            'min_thickness' => '10',
            'max_thickness' => '16',
            'exclusion_group' => 'cutting',
        ]);

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->where('operation_id', $operation->id)
            ->firstOrFail();

        $this->assertSame('10.00', $price->min_thickness);
        $this->assertSame('16.00', $price->max_thickness);
        $this->assertSame('cutting', $price->exclusion_group);
    }

    public function test_create_stores_null_predicates_when_fields_are_absent(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);
        $operation = $this->makeOperation();

        $this->invokeSave($operation->id, $supplier->id, $version->id, 80.0, [
            'name' => 'Кромкооблицовка ПВХ',
            'cost_per_unit' => 80,
            'unit' => 'м.п.',
            // predicate columns not mapped at all
        ]);

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->where('operation_id', $operation->id)
            ->firstOrFail();

        $this->assertNull($price->min_thickness);
        $this->assertNull($price->max_thickness);
        $this->assertNull($price->exclusion_group);
    }

    public function test_create_persists_exclusion_group_without_thickness(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);
        $operation = $this->makeOperation();

        $this->invokeSave($operation->id, $supplier->id, $version->id, 120.0, [
            'name' => 'Кромкооблицовка криволинейная',
            'cost_per_unit' => 120,
            'unit' => 'м.п.',
            'exclusion_group' => 'edging',
            // min_thickness / max_thickness not mapped
        ]);

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->where('operation_id', $operation->id)
            ->firstOrFail();

        $this->assertNull($price->min_thickness);
        $this->assertNull($price->max_thickness);
        $this->assertSame('edging', $price->exclusion_group);
    }

    public function test_create_unlinked_row_persists_predicates(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);

        // operation_id = null: price row not yet linked to a canonical operation
        $this->invokeSave(null, $supplier->id, $version->id, 200.0, [
            'name' => 'Фрезерование кромки unlinked',
            'cost_per_unit' => 200,
            'unit' => 'м.п.',
            'min_thickness' => '18',
            'max_thickness' => '25',
            'exclusion_group' => 'milling',
        ]);

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->whereNull('operation_id')
            ->where('source_name', 'Фрезерование кромки unlinked')
            ->firstOrFail();

        $this->assertSame('18.00', $price->min_thickness);
        $this->assertSame('25.00', $price->max_thickness);
        $this->assertSame('milling', $price->exclusion_group);
    }

    // -----------------------------------------------------------------------
    // Update path
    // -----------------------------------------------------------------------

    public function test_update_overwrites_predicate_fields(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);
        $operation = $this->makeOperation();

        // Seed an existing row with one thickness range
        OperationPrice::create([
            'supplier_id' => $supplier->id,
            'price_list_version_id' => $version->id,
            'operation_id' => $operation->id,
            'price_type' => OperationPrice::PRICE_TYPE_RETAIL,
            'price_per_internal_unit' => 100,
            'source_price' => 100,
            'source_unit' => 'м²',
            'currency' => 'RUB',
            'source_name' => 'Распиловка update test',
            'min_thickness' => 10,
            'max_thickness' => 16,
            'exclusion_group' => 'cutting',
        ]);

        // Re-import with a different range
        $this->invokeSave($operation->id, $supplier->id, $version->id, 120.0, [
            'name' => 'Распиловка update test',
            'cost_per_unit' => 120,
            'unit' => 'м²',
            'min_thickness' => '18',
            'max_thickness' => '22',
            'exclusion_group' => 'cutting',
        ]);

        // Only one row should exist (update, not duplicate create)
        $this->assertSame(
            1,
            OperationPrice::where('supplier_id', $supplier->id)
                ->where('price_list_version_id', $version->id)
                ->where('operation_id', $operation->id)
                ->count()
        );

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->where('operation_id', $operation->id)
            ->firstOrFail();

        $this->assertSame('18.00', $price->min_thickness);
        $this->assertSame('22.00', $price->max_thickness);
        $this->assertSame('cutting', $price->exclusion_group);
    }

    public function test_update_clears_predicates_when_not_mapped(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);
        $operation = $this->makeOperation();

        OperationPrice::create([
            'supplier_id' => $supplier->id,
            'price_list_version_id' => $version->id,
            'operation_id' => $operation->id,
            'price_type' => OperationPrice::PRICE_TYPE_RETAIL,
            'price_per_internal_unit' => 100,
            'source_price' => 100,
            'source_unit' => 'м²',
            'currency' => 'RUB',
            'source_name' => 'Кромка clear test',
            'min_thickness' => 10,
            'max_thickness' => 16,
            'exclusion_group' => 'cutting',
        ]);

        // Re-import without predicate columns in mapping
        $this->invokeSave($operation->id, $supplier->id, $version->id, 90.0, [
            'name' => 'Кромка clear test',
            'cost_per_unit' => 90,
            'unit' => 'м²',
        ]);

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->where('operation_id', $operation->id)
            ->firstOrFail();

        $this->assertNull($price->min_thickness);
        $this->assertNull($price->max_thickness);
        $this->assertNull($price->exclusion_group);
    }

    // -----------------------------------------------------------------------
    // Empty-string guard (UI may forward empty strings for unmapped columns)
    // -----------------------------------------------------------------------

    public function test_empty_string_predicates_are_stored_as_null(): void
    {
        $user = $this->makeUser();
        $supplier = $this->makeSupplier($user->id);
        $version = $this->makeVersion($supplier->id);
        $operation = $this->makeOperation();

        $this->invokeSave($operation->id, $supplier->id, $version->id, 50.0, [
            'name' => 'Операция пустые предикаты',
            'cost_per_unit' => 50,
            'unit' => 'шт.',
            'min_thickness' => '',
            'max_thickness' => '',
            'exclusion_group' => '',
        ]);

        $price = OperationPrice::where('supplier_id', $supplier->id)
            ->where('price_list_version_id', $version->id)
            ->where('operation_id', $operation->id)
            ->firstOrFail();

        $this->assertNull($price->min_thickness);
        $this->assertNull($price->max_thickness);
        $this->assertNull($price->exclusion_group);
    }
}
