<?php

namespace Tests\Feature\Billing;

use App\Evidence\EvidenceRunStatus;
use App\Models\BillingGateEvent;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\BillingGateService;
use App\Services\Billing\DTO\BillingGateResult;
use App\Services\ChromeExtractService;
use App\Services\EvidenceRunItemCollector;
use App\Services\TrustScoreService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BillingGateHooksTest extends TestCase
{
    use DatabaseTransactions;

    public function test_project_create_writes_would_block_event_but_still_creates_project(): void
    {
        $user = User::factory()->create();
        $this->subscribeToPlan($user, 'limited_projects', [
            BillingCodes::CAP_PROJECTS_MAX_OWNED => 1,
        ]);

        $this->makeProject($user, 'GATE-EXISTING-');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('GATE-CREATED-'),
                'expert_name' => 'Billing Gate',
                'address' => 'Billing address',
            ]);

        $response->assertCreated();

        $event = BillingGateEvent::query()
            ->where('user_id', $user->id)
            ->where('capability', BillingCodes::CAP_PROJECTS_MAX_OWNED)
            ->first();

        $this->assertNotNull($event);
        $this->assertTrue($event->would_block);
        $this->assertFalse($event->enforced);
        $this->assertSame('projects.create', $event->context_json['action'] ?? null);
    }

    public function test_project_create_continues_when_gate_service_throws(): void
    {
        $user = User::factory()->create();

        $this->mock(BillingGateService::class, function (MockInterface $mock) {
            $mock->shouldReceive('check')
                ->once()
                ->andThrow(new RuntimeException('Simulated gate failure.'));
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('GATE-FAILOPEN-'),
                'expert_name' => 'Billing Gate',
                'address' => 'Billing address',
            ]);

        $response->assertCreated();
    }

    public function test_pdf_endpoint_invokes_gate_service(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->mock(BillingGateService::class, function (MockInterface $mock) use ($user, $project) {
            $mock->shouldReceive('check')
                ->once()
                ->withArgs(fn ($actor, $capability, $context) => $actor->is($user)
                    && $capability === BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT
                    && ($context['action'] ?? null) === 'pdf.export'
                    && (int) ($context['project_id'] ?? 0) === (int) $project->id)
                ->andReturn($this->allowedResult(BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT));
        });

        $this->actingAs($user, 'sanctum')
            ->get("/api/smeta/pdf/{$project->id}");
    }

    public function test_evidence_run_invokes_gate_service_and_logs_context(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user, 'GATE-EVIDENCE-');
        $this->subscribeToPlan($user, 'limited_evidence', [
            BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT => 0,
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

        $response->assertCreated();

        $this->assertDatabaseHas('billing_gate_events', [
            'user_id' => $user->id,
            'capability' => BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT,
            'would_block' => true,
            'enforced' => false,
        ]);

        $event = BillingGateEvent::query()
            ->where('user_id', $user->id)
            ->where('capability', BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT)
            ->firstOrFail();

        $this->assertSame('evidence.run', $event->context_json['action'] ?? null);
        $this->assertSame($project->id, $event->context_json['project_id'] ?? null);
    }

    public function test_chrome_extract_with_evidence_invokes_gate_service_with_sanitized_host(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);

        $user = User::factory()->create();
        $this->subscribeToPlan($user, 'limited_chrome', [
            BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT => 0,
        ]);

        $material = new Material([
            'user_id' => $user->id,
            'name' => 'Gate material',
            'article' => 'GATE-MATERIAL',
            'type' => Material::TYPE_PLATE,
            'unit' => 'шт',
            'price_per_unit' => 100,
            'origin' => Material::ORIGIN_CHROME_EXT,
            'source_url' => 'https://supplier.example/catalog/item?secret=query',
            'is_active' => true,
        ]);
        $material->id = 12345;
        $material->exists = true;

        $this->mock(ChromeExtractService::class, function (MockInterface $mock) use ($material) {
            $mock->shouldReceive('createOrUpdateMaterial')
                ->once()
                ->andReturn([
                    'status' => 'ok',
                    'material' => $material,
                    'observation' => null,
                    'is_new' => true,
                    'dedup_match' => null,
                    'type_resolution' => null,
                    'errors' => [],
                ]);
        });

        $this->mock(TrustScoreService::class, function (MockInterface $mock) use ($material) {
            $mock->shouldReceive('recalculate')
                ->once()
                ->andReturn($material);
        });

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://supplier.example/catalog/item?secret=query',
                'extracted' => [
                    'title' => 'Gate material',
                    'price' => '100 RUB',
                ],
            ]);

        $response->assertCreated();

        $event = BillingGateEvent::query()
            ->where('user_id', $user->id)
            ->where('capability', BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT)
            ->firstOrFail();

        $this->assertTrue($event->would_block);
        $this->assertSame('chrome.extract_with_evidence', $event->context_json['action'] ?? null);
        $this->assertSame('supplier.example', $event->context_json['source_host'] ?? null);
        $this->assertArrayNotHasKey('source_url', $event->context_json);
    }

    public function test_legacy_unlimited_does_not_create_would_block_event(): void
    {
        config(['billing.default_plan' => 'legacy_unlimited']);

        $user = User::factory()->create();
        BillingPlan::query()->create([
            'code' => 'legacy_unlimited',
            'name' => 'Legacy Unlimited',
            'is_active' => true,
            'metadata_json' => [
                'limits' => array_fill_keys(BillingCodes::capabilities(), null),
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('GATE-LEGACY-'),
                'expert_name' => 'Billing Gate',
                'address' => 'Billing address',
            ]);

        $response->assertCreated();

        $this->assertDatabaseMissing('billing_gate_events', [
            'user_id' => $user->id,
            'would_block' => true,
        ]);
    }

    private function subscribeToPlan(User $user, string $planCode, array $limits): void
    {
        $plan = BillingPlan::query()->create([
            'code' => $planCode,
            'name' => $planCode,
            'is_active' => true,
            'metadata_json' => [
                'limits' => $limits,
            ],
        ]);

        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'source' => 'test',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function makeProject(User $user, string $prefix = 'GATE-PROJECT-'): Project
    {
        return Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid($prefix),
            'expert_name' => 'Billing Gate',
            'address' => 'Billing address',
        ]);
    }

    private function allowedResult(string $capability): BillingGateResult
    {
        return new BillingGateResult(
            allowed: true,
            logOnly: true,
            planCode: 'legacy_unlimited',
            capability: $capability,
            limit: null,
            usage: 0,
            wouldBlock: false,
            enforced: false,
            reason: 'allowed',
        );
    }
}
