<?php

namespace Tests\Unit\Services;

use App\Models\Material;
use App\Models\User;
use App\Services\Material\MaterialCanonicalResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MaterialCanonicalResolverTest extends TestCase
{
    use DatabaseTransactions;

    public function test_find_exact_match_returns_existing_material(): void
    {
        $user = User::factory()->create();
        $existing = $this->material(['user_id' => $user->id]);

        $match = app(MaterialCanonicalResolver::class)->findExactMatch([
            'user_id' => $user->id,
            'name' => '  ЛДСП   Дуб крафт белый 2500x1830x16  ',
            'article' => 'A-100',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'length_mm' => 2500,
            'width_mm' => 1830,
            'thickness_mm' => 16,
            'thickness' => 16,
        ]);

        $this->assertNotNull($match);
        $this->assertSame($existing->id, $match->id);
    }

    public function test_different_dimensions_are_not_exact_match(): void
    {
        $user = User::factory()->create();
        $this->material(['user_id' => $user->id, 'length_mm' => 2500]);

        $match = app(MaterialCanonicalResolver::class)->findExactMatch([
            'user_id' => $user->id,
            'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
            'article' => 'A-100',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'length_mm' => 2750,
            'width_mm' => 1830,
            'thickness_mm' => 16,
            'thickness' => 16,
        ]);

        $this->assertNull($match);
    }

    public function test_different_thickness_is_not_exact_match(): void
    {
        $user = User::factory()->create();
        $this->material(['user_id' => $user->id, 'thickness_mm' => 16, 'thickness' => 16]);

        $match = app(MaterialCanonicalResolver::class)->findExactMatch([
            'user_id' => $user->id,
            'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
            'article' => 'A-100',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'length_mm' => 2500,
            'width_mm' => 1830,
            'thickness_mm' => 18,
            'thickness' => 18,
        ]);

        $this->assertNull($match);
    }

    public function test_different_legacy_thickness_is_not_exact_match_when_thickness_mm_is_missing(): void
    {
        $user = User::factory()->create();
        $this->material(['user_id' => $user->id, 'thickness_mm' => null, 'thickness' => 16]);

        $match = app(MaterialCanonicalResolver::class)->findExactMatch([
            'user_id' => $user->id,
            'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
            'article' => 'A-100',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'length_mm' => 2500,
            'width_mm' => 1830,
            'thickness' => 18,
        ]);

        $this->assertNull($match);
    }

    public function test_different_article_is_not_exact_match(): void
    {
        $user = User::factory()->create();
        $this->material(['user_id' => $user->id, 'article' => 'A-100']);

        $match = app(MaterialCanonicalResolver::class)->findExactMatch([
            'user_id' => $user->id,
            'name' => 'ЛДСП Дуб крафт белый 2500x1830x16',
            'article' => 'B-200',
            'type' => Material::TYPE_PLATE,
            'unit' => 'м²',
            'length_mm' => 2500,
            'width_mm' => 1830,
            'thickness_mm' => 16,
            'thickness' => 16,
        ]);

        $this->assertNull($match);
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
