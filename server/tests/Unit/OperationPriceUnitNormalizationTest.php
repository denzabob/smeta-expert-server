<?php

namespace Tests\Unit;

use App\Models\Operation;
use App\Models\OperationPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationPriceUnitNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_m2_and_m2_superscript_are_treated_as_same_unit(): void
    {
        $operation = Operation::create([
            'name' => 'Распиловка ДСП 10-16-22-25 мм',
            'category' => 'Работы',
            'unit' => 'м²',
        ]);

        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'Test Supplier',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $priceListId = DB::table('price_lists')->insertGetId([
            'supplier_id' => $supplierId,
            'name' => 'Test operations',
            'type' => 'operations',
            'default_currency' => 'RUB',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('price_list_versions')->insertGetId([
            'price_list_id' => $priceListId,
            'version_number' => 1,
            'currency' => 'RUB',
            'status' => 'active',
            'source_type' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $price = OperationPrice::create([
            'supplier_id' => $supplierId,
            'price_list_version_id' => $versionId,
            'operation_id' => $operation->id,
            'source_price' => 177.1,
            'source_unit' => 'м2',
            'conversion_factor' => 1,
            'price_per_internal_unit' => 177.1,
            'currency' => 'RUB',
            'price_type' => 'retail',
        ]);

        $price->load('operation');

        $this->assertTrue($price->hasMatchingUnits());
        $this->assertTrue($price->canIncludeInMedian());
    }
}
