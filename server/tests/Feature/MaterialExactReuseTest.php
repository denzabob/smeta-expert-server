<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\User;
use App\Services\MaterialParseService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialExactReuseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_legacy_material_store_reuses_existing_exact_match(): void
    {
        $user = User::factory()->create();
        $existing = $this->material(['user_id' => $user->id]);
        $beforeCount = Material::query()->count();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/materials', [
                'origin' => 'user',
                'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
                'article' => 'A-100',
                'type' => Material::TYPE_PLATE,
                'unit' => 'м²',
                'price_per_unit' => 1200,
                'source_url' => 'https://example.com/material-a',
                'length_mm' => 2500,
                'width_mm' => 1830,
                'thickness_mm' => 16,
            ])
            ->assertOk()
            ->assertJsonPath('id', $existing->id);

        $this->assertSame($beforeCount, Material::query()->count());
    }

    public function test_material_parse_service_reuses_existing_exact_match_and_adds_observation(): void
    {
        $user = User::factory()->create();
        $existing = $this->material(['user_id' => $user->id]);
        $beforeCount = Material::query()->count();

        $material = app(MaterialParseService::class)->createMaterialWithObservation(
            [
                'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
                'article' => 'A-100',
                'type' => Material::TYPE_PLATE,
                'unit' => 'м²',
                'price_per_unit' => 1200,
                'source_url' => 'https://example.com/material-a',
                'length_mm' => 2500,
                'width_mm' => 1830,
                'thickness_mm' => 16,
                'thickness' => 16,
            ],
            [
                'price_per_unit' => 1200,
                'source_url' => 'https://example.com/material-a',
                'source_type' => 'web',
            ],
            $user->id
        );

        $this->assertSame($existing->id, $material->id);
        $this->assertSame($beforeCount, Material::query()->count());
        $this->assertDatabaseHas('material_price_histories', [
            'material_id' => $existing->id,
            'price_per_unit' => 1200,
        ]);
    }

    public function test_material_parse_service_creates_new_material_for_different_dimensions(): void
    {
        $user = User::factory()->create();
        $this->material(['user_id' => $user->id, 'length_mm' => 2500]);
        $beforeCount = Material::query()->count();

        $material = app(MaterialParseService::class)->createMaterialWithObservation(
            [
                'name' => 'ЛДСП Дуб крафт белый 2750x1830x16',
                'article' => 'A-100',
                'type' => Material::TYPE_PLATE,
                'unit' => 'м²',
                'price_per_unit' => 1200,
                'source_url' => 'https://example.com/material-b',
                'length_mm' => 2750,
                'width_mm' => 1830,
                'thickness_mm' => 16,
                'thickness' => 16,
            ],
            [
                'price_per_unit' => 1200,
                'source_url' => 'https://example.com/material-b',
                'source_type' => 'web',
            ],
            $user->id
        );

        $this->assertNotNull($material->id);
        $this->assertSame($beforeCount + 1, Material::query()->count());
    }

    public function test_audit_duplicates_command_runs_without_mutating_materials(): void
    {
        $this->material();
        $beforeCount = Material::query()->count();

        $this->artisan('materials:audit-duplicates --limit=5')
            ->assertExitCode(0);

        $this->assertSame($beforeCount, Material::query()->count());
    }

    private function material(array $overrides = []): Material
    {
        return Material::query()->create(array_merge([
            'user_id' => null,
            'origin' => 'user',
            'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
            'article' => 'A-100',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/existing-material',
            'length_mm' => 2500,
            'width_mm' => 1830,
            'thickness_mm' => 16,
            'thickness' => 16,
            'is_active' => true,
            'visibility' => Material::VISIBILITY_PRIVATE,
            'version' => 1,
        ], $overrides));
    }
}
