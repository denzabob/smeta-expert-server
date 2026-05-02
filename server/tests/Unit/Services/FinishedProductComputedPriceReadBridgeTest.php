<?php

namespace Tests\Unit\Services;

use App\Models\Material;
use App\Models\User;
use App\Services\FinishedProductComputedPriceReadBridge;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class FinishedProductComputedPriceReadBridgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement("ALTER TABLE materials MODIFY unit ENUM('м²','м.п.','шт') NOT NULL");
    }

    public function test_read_bridge_rejects_non_facade_materials(): void
    {
        $user = User::factory()->create();

        $material = Material::create([
            'user_id' => $user->id,
            'name' => 'Плита',
            'search_name' => 'плита',
            'article' => 'PLATE:test',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'is_active' => true,
        ]);

        $this->expectException(NotFoundHttpException::class);

        app(FinishedProductComputedPriceReadBridge::class)->forMaterial($material);
    }
}
