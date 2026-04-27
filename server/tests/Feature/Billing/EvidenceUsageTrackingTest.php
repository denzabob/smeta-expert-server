<?php

namespace Tests\Feature\Billing;

use App\Evidence\EvidenceRunStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\EvidenceRunItemCollector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class EvidenceUsageTrackingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_evidence_run_create_records_usage_event(): void
    {
        config(['billing.track_usage' => true]);

        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('BILL-EVIDENCE-'),
            'expert_name' => 'Billing Evidence',
            'address' => 'Billing address',
        ]);

        $this->mock(EvidenceRunItemCollector::class, function (MockInterface $mock) {
            $mock->shouldReceive('populateRun')
                ->once()
                ->andReturnUsing(function ($run) {
                    $run->status = EvidenceRunStatus::IN_PROGRESS;
                    $run->save();

                    return $run;
                });
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs", [
                'metadata' => ['source' => 'test'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $runId = $response->json('data.id');

        $this->assertDatabaseHas('usage_events', [
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'metric_code' => BillingCodes::METRIC_EVIDENCE_RUNS_CREATED,
            'feature_code' => BillingCodes::FEATURE_EVIDENCE_RUNS,
            'subject_type' => \App\Models\EstimateEvidenceRun::class,
            'subject_id' => $runId,
            'unit' => 'count',
            'source' => 'api',
        ]);
    }
}
