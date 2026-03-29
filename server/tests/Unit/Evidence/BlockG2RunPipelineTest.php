<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\Expense;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\ProjectLaborWork;
use App\Models\ProjectPosition;
use App\Models\User;
use App\Services\EvidenceRunFinalizer;
use App\Services\EvidenceRunItemCollector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockG2RunPipelineTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Schema – new columns from G2 migration
    // ──────────────────────────────────────────────────────────────

    public function test_estimate_evidence_items_has_label_column(): void
    {
        $this->assertTrue(Schema::hasColumn('estimate_evidence_items', 'label'));
    }

    public function test_estimate_evidence_items_has_effective_value_column(): void
    {
        $this->assertTrue(Schema::hasColumn('estimate_evidence_items', 'effective_value'));
    }

    public function test_estimate_evidence_items_has_currency_column(): void
    {
        $this->assertTrue(Schema::hasColumn('estimate_evidence_items', 'currency'));
    }

    public function test_estimate_evidence_runs_has_snapshot_json_column(): void
    {
        $this->assertTrue(Schema::hasColumn('estimate_evidence_runs', 'snapshot_json'));
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Enum helpers added in G2
    // ──────────────────────────────────────────────────────────────

    public function test_cost_component_internal_only_types(): void
    {
        $internal = CostComponent::internalOnlyTypes();
        $this->assertContains(CostComponent::OPERATION, $internal);
        $this->assertContains(CostComponent::LABOR_WORK, $internal);
        $this->assertContains(CostComponent::EXPENSE, $internal);
        $this->assertNotContains(CostComponent::PLATE, $internal);
        $this->assertNotContains(CostComponent::EDGE, $internal);
    }

    public function test_cost_component_external_types(): void
    {
        $external = CostComponent::externalTypes();
        $this->assertContains(CostComponent::PLATE, $external);
        $this->assertContains(CostComponent::EDGE, $external);
        $this->assertContains(CostComponent::FACADE, $external);
        $this->assertContains(CostComponent::FITTING, $external);
        $this->assertNotContains(CostComponent::OPERATION, $external);
    }

    public function test_evidence_item_status_terminal_statuses(): void
    {
        $terminal = EvidenceItemStatus::terminalStatuses();
        $this->assertContains(EvidenceItemStatus::RESOLVED, $terminal);
        $this->assertContains(EvidenceItemStatus::FAILED, $terminal);
        $this->assertContains(EvidenceItemStatus::SKIPPED, $terminal);
        $this->assertNotContains(EvidenceItemStatus::PENDING, $terminal);
        $this->assertNotContains(EvidenceItemStatus::COLLECTING, $terminal);
    }

    public function test_evidence_item_status_completed_statuses(): void
    {
        $completed = EvidenceItemStatus::completedStatuses();
        $this->assertContains(EvidenceItemStatus::RESOLVED, $completed);
        $this->assertContains(EvidenceItemStatus::SKIPPED, $completed);
        $this->assertNotContains(EvidenceItemStatus::FAILED, $completed);
    }

    public function test_evidence_run_status_finalizable_only_ready(): void
    {
        $finalizable = EvidenceRunStatus::finalizableStatuses();
        $this->assertContains(EvidenceRunStatus::READY, $finalizable);
        $this->assertCount(1, $finalizable);
    }

    public function test_evidence_run_status_terminal_statuses(): void
    {
        $terminal = EvidenceRunStatus::terminalStatuses();
        $this->assertContains(EvidenceRunStatus::FINALIZED, $terminal);
        $this->assertContains(EvidenceRunStatus::FAILED, $terminal);
        $this->assertNotContains(EvidenceRunStatus::IN_PROGRESS, $terminal);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Service layer – EvidenceRunItemCollector
    // ──────────────────────────────────────────────────────────────

    public function test_item_collector_populates_items_from_project(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $run = $this->makeRun($project, $user);
        $collector = app(EvidenceRunItemCollector::class);
        $run = $collector->populateRun($run, $project, $user->id);

        // Should have at least fittings and expenses
        $this->assertGreaterThanOrEqual(2, $run->total_items);
        $this->assertNotNull($run->started_at);
    }

    public function test_item_collector_auto_resolves_expenses(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $run = $this->makeRun($project, $user);
        $collector = app(EvidenceRunItemCollector::class);
        $collector->populateRun($run, $project, $user->id);

        $expenseItems = $run->items()
            ->where('cost_component', CostComponent::EXPENSE)
            ->get();

        foreach ($expenseItems as $item) {
            $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
            $this->assertEquals('auto', $item->resolution_type);
            $this->assertNotNull($item->evidence_record_id, 'Auto-resolved expense should have evidence_record_id');
        }
    }

    public function test_item_collector_leaves_fittings_pending(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $run = $this->makeRun($project, $user);
        $collector = app(EvidenceRunItemCollector::class);
        $collector->populateRun($run, $project, $user->id);

        $fittingItems = $run->items()
            ->where('cost_component', CostComponent::FITTING)
            ->get();

        foreach ($fittingItems as $item) {
            $this->assertEquals(EvidenceItemStatus::PENDING, $item->status);
            $this->assertNull($item->resolution_type);
            $this->assertNull($item->evidence_record_id);
        }
    }

    public function test_item_collector_sets_run_in_progress_when_has_items(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $run = $this->makeRun($project, $user);
        $collector = app(EvidenceRunItemCollector::class);
        $run = $collector->populateRun($run, $project, $user->id);

        $this->assertEquals(EvidenceRunStatus::IN_PROGRESS, $run->status);
    }

    public function test_item_collector_sets_labels_and_values(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $run = $this->makeRun($project, $user);
        $collector = app(EvidenceRunItemCollector::class);
        $collector->populateRun($run, $project, $user->id);

        $items = $run->items()->get();
        foreach ($items as $item) {
            $this->assertNotNull($item->label, "Item {$item->uuid} should have a label");
            $this->assertNotNull($item->uuid, "Item should have a uuid");
            $this->assertNotNull($item->cost_component, "Item should have a cost_component");
        }
    }

    public function test_item_collector_counters_accuracy(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $run = $this->makeRun($project, $user);
        $collector = app(EvidenceRunItemCollector::class);
        $run = $collector->populateRun($run, $project, $user->id);

        $items = $run->items()->get();
        $resolvedCount = $items->where('status', EvidenceItemStatus::RESOLVED)->count();
        $failedCount = $items->where('status', EvidenceItemStatus::FAILED)->count();

        $this->assertEquals($items->count(), $run->total_items);
        $this->assertEquals($resolvedCount, $run->completed_items);
        $this->assertEquals($failedCount, $run->failed_items);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Service layer – EvidenceRunFinalizer
    // ──────────────────────────────────────────────────────────────

    public function test_finalizer_rejects_non_ready_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS]);

        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $finalizer = app(EvidenceRunFinalizer::class);
        $check = $finalizer->canFinalize($run);

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('not finalizable', $check['reason']);
    }

    public function test_finalizer_rejects_run_with_no_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY]);

        $finalizer = app(EvidenceRunFinalizer::class);
        $check = $finalizer->canFinalize($run);

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('no items', $check['reason']);
    }

    public function test_finalizer_rejects_run_with_pending_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY]);

        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::PENDING);

        $finalizer = app(EvidenceRunFinalizer::class);
        $check = $finalizer->canFinalize($run);

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('non-terminal', $check['reason']);
    }

    public function test_finalizer_accepts_all_terminal_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY]);

        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::SKIPPED);
        $this->makeItem($run, CostComponent::OPERATION, EvidenceItemStatus::RESOLVED);

        $finalizer = app(EvidenceRunFinalizer::class);
        $check = $finalizer->canFinalize($run);

        $this->assertTrue($check['ok']);
    }

    public function test_finalizer_produces_snapshot_with_five_keys(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY]);

        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::RESOLVED);

        $finalizer = app(EvidenceRunFinalizer::class);
        $run = $finalizer->finalize($run);

        $this->assertEquals(EvidenceRunStatus::FINALIZED, $run->status);
        $this->assertNotNull($run->finalized_at);
        $this->assertNotNull($run->snapshot_json);

        $snapshot = $run->snapshot_json;
        $this->assertArrayHasKey('evidence_coverage_summary', $snapshot);
        $this->assertArrayHasKey('evidence_items', $snapshot);
        $this->assertArrayHasKey('evidence_records', $snapshot);
        $this->assertArrayHasKey('exceptions', $snapshot);
        $this->assertArrayHasKey('generation_meta', $snapshot);
    }

    public function test_finalizer_snapshot_coverage_summary_has_counts(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY]);

        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::SKIPPED);

        $finalizer = app(EvidenceRunFinalizer::class);
        $run = $finalizer->finalize($run);

        $summary = $run->snapshot_json['evidence_coverage_summary'];
        $this->assertEquals(2, $summary['total_items']);
        $this->assertArrayHasKey('by_status', $summary);
        $this->assertArrayHasKey('by_component', $summary);
    }

    public function test_finalizer_snapshot_exceptions_include_skipped(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY]);

        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $skipped = $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::SKIPPED);

        $finalizer = app(EvidenceRunFinalizer::class);
        $run = $finalizer->finalize($run);

        $exceptions = $run->snapshot_json['exceptions'];
        $this->assertCount(1, $exceptions);
        $this->assertEquals($skipped->uuid, $exceptions[0]['uuid']);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. API – store endpoint populates items
    // ──────────────────────────────────────────────────────────────

    public function test_store_endpoint_creates_run_with_items(): void
    {
        [$user, $project] = $this->makeProjectWithFittingAndExpense();

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs",
            ['metadata' => ['source' => 'test']]
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['id', 'uuid', 'status', 'total_items', 'items'],
            ]);

        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(2, $data['total_items']);
        $this->assertEquals(EvidenceRunStatus::IN_PROGRESS, $data['status']);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. API – resolve item
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_item_links_evidence_record(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $record = $this->makeEvidenceRecord($user, CostComponent::PLATE, 1500.00);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
            ['evidence_record_id' => $record->id]
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', EvidenceItemStatus::RESOLVED);

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertEquals($record->id, $item->evidence_record_id);
        $this->assertEquals('manual', $item->resolution_type);
    }

    public function test_resolve_item_transitions_run_to_ready(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $record = $this->makeEvidenceRecord($user, CostComponent::PLATE, 1500.00);

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
            ['evidence_record_id' => $record->id]
        );

        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::READY, $run->status);
        $this->assertEquals(1, $run->completed_items);
    }

    public function test_resolve_item_rejects_already_terminal(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);

        $record = $this->makeEvidenceRecord($user, CostComponent::PLATE, 1500.00);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
            ['evidence_record_id' => $record->id]
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_resolve_item_rejects_finalized_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::FINALIZED, 'finalized_at' => now()]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $record = $this->makeEvidenceRecord($user, CostComponent::PLATE, 1500.00);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve",
            ['evidence_record_id' => $record->id]
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. API – skip item
    // ──────────────────────────────────────────────────────────────

    public function test_skip_item_marks_as_skipped(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::FITTING, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/skip",
            ['reason' => 'No supplier found']
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', EvidenceItemStatus::SKIPPED);

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::SKIPPED, $item->status);
        $this->assertEquals('skipped', $item->resolution_type);
    }

    public function test_skip_item_transitions_run_to_ready(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::FITTING, EvidenceItemStatus::PENDING);

        $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/skip"
        );

        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::READY, $run->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 8. API – finalize endpoint
    // ──────────────────────────────────────────────────────────────

    public function test_finalize_endpoint_rejects_incomplete_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 2]);
        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/finalize"
        );

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_finalize_endpoint_succeeds_when_all_resolved(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::READY, 'total_items' => 2]);
        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);
        $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::RESOLVED);

        $response = $this->actingAs($user)->postJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}/finalize"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', EvidenceRunStatus::FINALIZED);

        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::FINALIZED, $run->status);
        $this->assertNotNull($run->finalized_at);
        $this->assertNotNull($run->snapshot_json);
    }

    // ──────────────────────────────────────────────────────────────
    // 9. Backward compatibility – legacy G1 routes still work
    // ──────────────────────────────────────────────────────────────

    public function test_show_endpoint_still_works(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);

        $response = $this->actingAs($user)->getJson(
            "/api/projects/{$project->id}/evidence-runs/{$run->id}"
        );

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $run->id);
    }

    public function test_create_record_endpoint_still_works(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/evidence-records', [
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::MANUAL_INPUT,
            'capture_method'      => CaptureMethod::MANUAL_ENTRY,
            'observed_price'      => 2500.00,
            'currency'            => 'RUB',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'             => $user->id,
            'number'              => 'PRJ-G2-' . Str::random(4),
            'expert_name'         => 'Test Expert',
            'address'             => 'Test Address',
            'waste_coefficient'   => 1.0,
            'repair_coefficient'  => 1.0,
        ]);
    }

    private function makeMaterial(User $user, string $type = 'plate'): Material
    {
        return Material::create([
            'user_id'        => $user->id,
            'origin'         => 'user',
            'name'           => 'Тест ' . $type . ' ' . Str::random(4),
            'article'        => 'TEST-' . Str::random(4),
            'type'           => $type,
            'unit'           => $type === 'edge' ? 'м.п.' : 'м²',
            'price_per_unit' => 1000,
            'source_url'     => 'https://example.com/product?id=1',
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'manual',
            'trust_level'    => 'unverified',
        ]);
    }

    private function makeRun(Project $project, User $user): EstimateEvidenceRun
    {
        return EstimateEvidenceRun::create([
            'uuid'           => (string) Str::uuid(),
            'project_id'     => $project->id,
            'initiated_by'   => $user->id,
            'status'         => EvidenceRunStatus::PENDING,
            'total_items'    => 0,
            'completed_items' => 0,
            'failed_items'   => 0,
        ]);
    }

    private function makeItem(
        EstimateEvidenceRun $run,
        string $costComponent,
        string $status,
    ): EstimateEvidenceItem {
        return EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => $costComponent,
            'label'           => 'Test ' . $costComponent,
            'status'          => $status,
            'subject_type'    => 'test',
            'subject_id'      => 1,
        ]);
    }

    private function makeEvidenceRecord(User $user, string $costComponent, float $price): EvidenceRecord
    {
        return EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => $costComponent,
            'source_type'         => SourceType::MANUAL_INPUT,
            'capture_method'      => CaptureMethod::MANUAL_ENTRY,
            'verification_status' => VerificationStatus::PENDING,
            'observed_price'      => $price,
            'currency'            => 'RUB',
            'extracted_name'      => 'Test record',
            'trust_score'         => 80,
            'created_by'          => $user->id,
        ]);
    }

    /**
     * Create a project with a fitting and an expense → guarantees the report
     * will include at least one external (fitting) and one internal (expense) item.
     */
    private function makeProjectWithFittingAndExpense(): array
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $material = $this->makeMaterial($user, 'hardware');

        ProjectFitting::create([
            'project_id'  => $project->id,
            'material_id' => $material->id,
            'name'        => 'Петля мебельная',
            'quantity'    => 4,
            'unit_price'  => 150.00,
            'source_url'  => 'https://example.com/hinge',
        ]);

        Expense::create([
            'project_id' => $project->id,
            'name'       => 'Доставка',
            'amount'     => 3000.00,
        ]);

        return [$user, $project];
    }
}
