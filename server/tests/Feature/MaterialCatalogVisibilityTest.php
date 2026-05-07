<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialCatalogVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_my_catalog_returns_only_current_user_materials(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownPrivate = $this->material([
            'user_id' => $user->id,
            'name' => 'Own private material',
            'visibility' => Material::VISIBILITY_PRIVATE,
        ]);
        $ownPublic = $this->material([
            'user_id' => $user->id,
            'name' => 'Own public material',
            'visibility' => Material::VISIBILITY_PUBLIC,
        ]);
        $this->material([
            'user_id' => $otherUser->id,
            'name' => 'Other private material',
            'visibility' => Material::VISIBILITY_PRIVATE,
        ]);
        $this->material([
            'user_id' => null,
            'name' => 'System parser material',
            'origin' => 'parser',
            'visibility' => Material::VISIBILITY_PRIVATE,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/materials/catalog?mode=my')
            ->assertOk()
            ->assertJsonPath('meta.mode', 'my');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($ownPrivate->id, $ids);
        $this->assertContains($ownPublic->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_public_catalog_excludes_private_user_materials_and_includes_public_admin_and_system_materials(): void
    {
        $viewer = User::factory()->create();
        $regularUser = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $regularPrivateManual = $this->material([
            'user_id' => $regularUser->id,
            'name' => 'Regular private manual',
            'visibility' => Material::VISIBILITY_PRIVATE,
            'data_origin' => Material::ORIGIN_MANUAL,
        ]);
        $regularPrivateChrome = $this->material([
            'user_id' => $regularUser->id,
            'name' => 'Regular private chrome',
            'visibility' => Material::VISIBILITY_PRIVATE,
            'data_origin' => Material::ORIGIN_CHROME_EXT,
            'origin' => 'parser',
        ]);
        $regularPublic = $this->material([
            'user_id' => $regularUser->id,
            'name' => 'Regular explicitly public',
            'visibility' => Material::VISIBILITY_PUBLIC,
        ]);
        $adminPrivate = $this->material([
            'user_id' => $admin->id,
            'name' => 'Admin catalog material',
            'visibility' => Material::VISIBILITY_PRIVATE,
        ]);
        $systemParser = $this->material([
            'user_id' => null,
            'name' => 'System parser material',
            'origin' => 'parser',
            'visibility' => Material::VISIBILITY_PRIVATE,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/materials/catalog?mode=public')
            ->assertOk()
            ->assertJsonPath('meta.mode', 'public');

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($regularPrivateManual->id, $ids);
        $this->assertNotContains($regularPrivateChrome->id, $ids);
        $this->assertContains($regularPublic->id, $ids);
        $this->assertContains($adminPrivate->id, $ids);
        $this->assertContains($systemParser->id, $ids);
    }

    private function material(array $overrides = []): Material
    {
        return Material::query()->create(array_merge([
            'user_id' => null,
            'origin' => 'user',
            'name' => 'Test material',
            'article' => 'TEST-' . uniqid(),
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/material-' . uniqid(),
            'length_mm' => 2800,
            'width_mm' => 2070,
            'thickness_mm' => 16,
            'thickness' => 16,
            'is_active' => true,
            'visibility' => Material::VISIBILITY_PRIVATE,
            'data_origin' => Material::ORIGIN_MANUAL,
            'version' => 1,
        ], $overrides));
    }
}
