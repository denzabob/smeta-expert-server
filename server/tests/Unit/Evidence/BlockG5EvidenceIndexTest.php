<?php

namespace Tests\Unit\Evidence;

use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockG5EvidenceIndexTest extends TestCase
{
    use DatabaseTransactions;

    // ────────────────────────────────────────────
    // 1. Index returns empty list for project with no runs
    // ────────────────────────────────────────────

    public function test_index_returns_empty_list_when_no_runs(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs"
        );

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(0, 'data');
    }

    // ────────────────────────────────────────────
    // 2. Index returns runs for project ordered by id desc
    // ────────────────────────────────────────────

    public function test_index_returns_runs_ordered_desc(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run1 = $this->makeRun($project, $user, EvidenceRunStatus::FINALIZED);
        $run2 = $this->makeRun($project, $user, EvidenceRunStatus::PENDING);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs"
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        // Newest first
        $this->assertEquals($run2->id, $response->json('data.0.id'));
        $this->assertEquals($run1->id, $response->json('data.1.id'));
    }

    // ────────────────────────────────────────────
    // 3. Index scopes to project — does not leak other project's runs
    // ────────────────────────────────────────────

    public function test_index_scopes_to_project(): void
    {
        $user = User::factory()->create();
        $projectA = $this->makeProject($user);
        $projectB = $this->makeProject($user);

        $this->makeRun($projectA, $user);
        $this->makeRun($projectB, $user);
        $this->makeRun($projectB, $user);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$projectA->id}/evidence-runs"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    // ────────────────────────────────────────────
    // 4. Index requires auth
    // ────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->getJson(
            "/api/projects/{$project->id}/evidence-runs"
        );

        $response->assertUnauthorized();
    }

    // ────────────────────────────────────────────
    // 5. Index rejects another user's project (403)
    // ────────────────────────────────────────────

    public function test_index_rejects_another_users_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->makeProject($owner);

        $response = $this->actingAs($other)->getJson(
            "/api/projects/{$project->id}/evidence-runs"
        );

        $response->assertForbidden();
    }

    // ────────────────────────────────────────────
    // 6. Index returns correct fields
    // ────────────────────────────────────────────

    public function test_index_returns_expected_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeRun($project, $user, EvidenceRunStatus::IN_PROGRESS);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs"
        );

        $response->assertOk();
        $run = $response->json('data.0');
        $this->assertArrayHasKey('id', $run);
        $this->assertArrayHasKey('uuid', $run);
        $this->assertArrayHasKey('status', $run);
        $this->assertArrayHasKey('total_items', $run);
        $this->assertArrayHasKey('completed_items', $run);
        $this->assertArrayHasKey('failed_items', $run);
    }

    // ────────────────────────────────────────────
    // 7. Index works regardless of genericChromeEnabled flag
    // ────────────────────────────────────────────

    public function test_index_works_when_generic_chrome_disabled(): void
    {
        config()->set('smeta.evidence.generic_chrome_enabled', false);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeRun($project, $user);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs"
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    // ────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'           => $user->id,
            'number'            => 'PRJ-G5-' . Str::random(4),
            'expert_name'       => 'Test Expert G5',
            'address'           => 'Test Address G5',
            'waste_coefficient' => 1.0,
            'repair_coefficient' => 1.0,
        ]);
    }

    private function makeRun(
        Project $project,
        User $user,
        string $status = EvidenceRunStatus::PENDING,
    ): EstimateEvidenceRun {
        return EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => $status,
            'total_items'     => 0,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);
    }
}
