<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectRegionUpdateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_project_region_can_be_updated_from_settings_payload(): void
    {
        $user = User::factory()->create();
        $regionId = DB::table('regions')->insertGetId([
            'region_name' => 'Тестовый регион',
            'capital_city' => 'Тестовый город',
            'code' => 'test-region-' . uniqid(),
            'is_active' => true,
            'sort_order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => 'REGION-UPDATE',
            'expert_name' => 'Expert',
            'address' => 'Address',
            'region_id' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'region_id' => $regionId,
            ])
            ->assertOk()
            ->assertJsonPath('region_id', $regionId);

        $this->assertEquals($regionId, $project->fresh()->region_id);
    }
}
