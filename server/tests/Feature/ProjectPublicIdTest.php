<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
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
