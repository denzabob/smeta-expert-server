<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProjectUsageTrackingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_project_create_records_usage_event(): void
    {
        config(['billing.track_usage' => true]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('BILL-PROJECT-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ]);

        $response->assertStatus(201);

        $projectId = $response->json('id');

        $this->assertDatabaseHas('usage_events', [
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'user_id' => $user->id,
            'project_id' => $projectId,
            'metric_code' => BillingCodes::METRIC_PROJECTS_CREATED,
            'feature_code' => BillingCodes::FEATURE_PROJECTS_CREATE,
            'subject_type' => Project::class,
            'subject_id' => $projectId,
            'unit' => 'count',
            'source' => 'api',
        ]);
    }

    public function test_project_archive_records_usage_event(): void
    {
        config(['billing.track_usage' => true]);

        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('BILL-ARCHIVE-'),
            'expert_name' => 'Billing Expert',
            'address' => 'Billing address',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/projects/{$project->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Проект архивирован']);

        $this->assertDatabaseHas('usage_events', [
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'metric_code' => BillingCodes::METRIC_PROJECTS_ARCHIVED,
            'feature_code' => BillingCodes::FEATURE_PROJECTS_ARCHIVE,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'unit' => 'count',
            'source' => 'api',
        ]);
    }

    public function test_tracking_disabled_does_not_create_usage_event(): void
    {
        config(['billing.track_usage' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('BILL-DISABLED-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ]);

        $response->assertStatus(201);

        $projectId = $response->json('id');

        $this->assertDatabaseMissing('usage_events', [
            'project_id' => $projectId,
            'metric_code' => BillingCodes::METRIC_PROJECTS_CREATED,
        ]);
    }
}
