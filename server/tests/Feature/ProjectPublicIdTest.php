<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectPublicIdTest extends TestCase
{
    use DatabaseTransactions;

    public function test_new_project_gets_public_id(): void
    {
        $user = User::factory()->create();

        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => 'Public ID project',
            'expert_name' => 'Expert',
            'address' => 'Address',
        ]);

        $this->assertNotEmpty($project->public_id);
        $this->assertStringStartsWith('prj_', $project->public_id);
        $this->assertMatchesRegularExpression('/^prj_[0-9a-hjkmnp-tv-z]{26}$/', $project->public_id);
    }

    public function test_owner_can_open_project_by_public_id(): void
    {
        [$user, $project] = $this->makeProject();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->public_id}")
            ->assertOk()
            ->assertJsonPath('id', $project->id)
            ->assertJsonPath('public_id', $project->public_id);
    }

    public function test_other_user_cannot_open_project_by_public_id(): void
    {
        [, $project] = $this->makeProject();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/projects/{$project->public_id}")
            ->assertForbidden();
    }

    public function test_numeric_project_id_temporarily_still_works_for_owner(): void
    {
        [$user, $project] = $this->makeProject();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('public_id', $project->public_id);
    }

    public function test_numeric_project_id_does_not_bypass_access_policy(): void
    {
        [, $project] = $this->makeProject();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/projects/{$project->id}")
            ->assertForbidden();
    }

    public function test_project_creation_normalizes_large_default_text_blocks(): void
    {
        $user = User::factory()->create();

        UserSettings::query()->create([
            'user_id' => $user->id,
            'waste_coefficient' => 1.0,
            'repair_coefficient' => 1.0,
            'apply_waste_to_plate' => true,
            'apply_waste_to_edge' => true,
            'apply_waste_to_operations' => false,
            'use_area_calc_mode' => false,
            'text_blocks' => [
                [
                    'title' => str_repeat('A', 400),
                    'text' => str_repeat('Большой текст ', 1200),
                    'enabled' => true,
                ],
                str_repeat('Строковый блок ', 1200),
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', []);

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'public_id', 'text_blocks']);

        $blocks = $response->json('text_blocks');
        $this->assertCount(2, $blocks);
        $this->assertLessThanOrEqual(255, mb_strlen($blocks[0]['title']));
        $this->assertLessThanOrEqual(10000, mb_strlen($blocks[0]['text']));
        $this->assertLessThanOrEqual(10000, mb_strlen($blocks[1]['text']));
    }

    private function makeProject(): array
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => 'Public route project',
            'expert_name' => 'Expert',
            'address' => 'Address',
        ]);

        return [$user, $project];
    }
}
