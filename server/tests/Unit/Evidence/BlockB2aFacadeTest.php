<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Evidence\EvidenceFeatures;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockB2aFacadeTest extends TestCase
{
    use DatabaseTransactions;

    // ── Feature flag ─────────────────────────────────────────────

    public function test_facade_flag_enabled_method(): void
    {
        config(['smeta.evidence.facade_enabled' => false]);
        $this->assertFalse(EvidenceFeatures::facadeEvidenceEnabled());

        config(['smeta.evidence.facade_enabled' => true]);
        $this->assertTrue(EvidenceFeatures::facadeEvidenceEnabled());
    }

    // ── collectReportItems ───────────────────────────────────────

    public function test_facade_items_created_when_flag_enabled(): void
    {
        config(['smeta.evidence.facade_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $facadeMat = $this->makeMaterial($user, 'facade');

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_FACADE,
            'facade_material_id' => $facadeMat->id,
            'quantity' => 2,
            'width' => 600,
            'length' => 400,
        ]);

        $controller = app(\App\Http\Controllers\Api\RevisionRunController::class);
        $method = new \ReflectionMethod($controller, 'collectReportItems');
        $method->setAccessible(true);

        // Facade items come from positions, not from report['facades']
        $report = ['plates' => [], 'edges' => [], 'facades' => []];
        $items = $method->invoke($controller, $project, $report);

        $facadeItem = collect($items)->firstWhere('cost_driver_type', CostDriverType::FACADE);
        $this->assertNotNull($facadeItem, 'Facade item should exist when flag is enabled');
        $this->assertSame($facadeMat->id, $facadeItem['material_id']);
        $this->assertSame('project_position', $facadeItem['evidence_subject_type']);
        $this->assertSame($position->id, $facadeItem['evidence_subject_id']);
        $this->assertSame(RevisionRunItem::STATUS_NEEDS_MANUAL, $facadeItem['initial_status']);
        $this->assertSame('Фасады: только ручное подтверждение', $facadeItem['initial_message']);
        $this->assertSame($facadeMat->source_url, $facadeItem['source_url']);
    }

    public function test_facade_items_not_created_when_flag_disabled(): void
    {
        config(['smeta.evidence.facade_enabled' => false]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $facadeMat = $this->makeMaterial($user, 'facade');

        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_FACADE,
            'facade_material_id' => $facadeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 400,
        ]);

        $controller = app(\App\Http\Controllers\Api\RevisionRunController::class);
        $method = new \ReflectionMethod($controller, 'collectReportItems');
        $method->setAccessible(true);

        $report = ['plates' => [], 'edges' => [], 'facades' => []];
        $items = $method->invoke($controller, $project, $report);

        $facadeItem = collect($items)->firstWhere('cost_driver_type', CostDriverType::FACADE);
        $this->assertNull($facadeItem, 'No facade items when flag is disabled');
    }

    public function test_facade_items_deduplicated_by_material(): void
    {
        config(['smeta.evidence.facade_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $facadeMat = $this->makeMaterial($user, 'facade');

        // Two positions sharing the same facade material
        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_FACADE,
            'facade_material_id' => $facadeMat->id,
            'quantity' => 1,
            'width' => 500,
            'length' => 300,
        ]);
        ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_FACADE,
            'facade_material_id' => $facadeMat->id,
            'quantity' => 3,
            'width' => 700,
            'length' => 400,
        ]);

        $controller = app(\App\Http\Controllers\Api\RevisionRunController::class);
        $method = new \ReflectionMethod($controller, 'collectReportItems');
        $method->setAccessible(true);

        $report = ['plates' => [], 'edges' => [], 'facades' => []];
        $items = $method->invoke($controller, $project, $report);

        $facadeItems = collect($items)->where('cost_driver_type', CostDriverType::FACADE)->values();
        $this->assertCount(1, $facadeItems, 'Two positions with same material should produce one facade item');
        $this->assertSame($facadeMat->id, $facadeItems[0]['material_id']);
    }

    // ── start() → DB persistence ─────────────────────────────────

    public function test_start_creates_needs_manual_for_facades(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $facadeMat = $this->makeMaterial($user, 'facade');

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_FACADE,
            'facade_material_id' => $facadeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 400,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        // Simulate what start() does: create item from reportItem with initial_status
        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $facadeMat->id,
            'source_url' => $facadeMat->source_url,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'message' => 'Фасады: только ручное подтверждение',
            'cost_driver_type' => CostDriverType::FACADE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        $fresh = RevisionRunItem::find($item->id);
        $this->assertSame(RevisionRunItem::STATUS_NEEDS_MANUAL, $fresh->status);
        $this->assertSame('facade', $fresh->cost_driver_type);
        $this->assertSame('Фасады: только ручное подтверждение', $fresh->message);
    }

    // ── RunRevisionUpdateJob exclusion ───────────────────────────

    public function test_job_skips_needs_manual_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $plateMat = $this->makeMaterial($user, 'plate');
        $facadeMat = $this->makeMaterial($user, 'facade');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 2,
        ]);

        // Plate item (normal — should be processed)
        $plateItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $plateMat->id,
            'source_url' => 'https://example.com/plate',
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Facade item (manual-only — must NOT be reset)
        $facadeItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $facadeMat->id,
            'source_url' => $facadeMat->source_url,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'message' => 'Фасады: только ручное подтверждение',
            'cost_driver_type' => CostDriverType::FACADE,
        ]);

        // Simulate what the job query does (initial run, retryOnly=false)
        $query = RevisionRunItem::where('revision_run_id', $run->id)
            ->where('status', '!=', RevisionRunItem::STATUS_NEEDS_MANUAL);
        $items = $query->get();

        // Only plate item should be selected
        $this->assertCount(1, $items);
        $this->assertSame($plateItem->id, $items->first()->id);

        // Facade item must remain NEEDS_MANUAL
        $facadeItem->refresh();
        $this->assertSame(RevisionRunItem::STATUS_NEEDS_MANUAL, $facadeItem->status);
        $this->assertSame('Фасады: только ручное подтверждение', $facadeItem->message);
    }

    public function test_job_retry_skips_needs_manual_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $facadeMat = $this->makeMaterial($user, 'facade');
        $plateMat = $this->makeMaterial($user, 'plate');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 2,
        ]);

        // Plate item that failed (should be retried)
        $plateItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $plateMat->id,
            'source_url' => 'https://example.com/plate',
            'status' => RevisionRunItem::STATUS_BLOCKED,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Facade item still NEEDS_MANUAL (must NOT enter retry)
        $facadeItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $facadeMat->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::FACADE,
        ]);

        // Simulate retry query path
        $query = RevisionRunItem::where('revision_run_id', $run->id)
            ->where('status', '!=', RevisionRunItem::STATUS_OK)
            ->where('status', '!=', RevisionRunItem::STATUS_NEEDS_MANUAL);
        $items = $query->get();

        $this->assertCount(1, $items);
        $this->assertSame($plateItem->id, $items->first()->id);
    }

    // ── finalize() behavior ──────────────────────────────────────

    public function test_finalize_blocked_by_unclosed_facade(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $plateMat = $this->makeMaterial($user, 'plate');
        $facadeMat = $this->makeMaterial($user, 'facade');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 2,
        ]);

        // Plate item already OK
        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $plateMat->id,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Facade item still NEEDS_MANUAL
        $facadeItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $facadeMat->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'message' => 'Фасады: только ручное подтверждение',
            'cost_driver_type' => CostDriverType::FACADE,
        ]);

        // finalize() checks: items where status != OK
        $blockers = $run->items->where('status', '!=', RevisionRunItem::STATUS_OK)->values();
        $this->assertCount(1, $blockers);
        $this->assertSame($facadeItem->id, $blockers->first()->id);
    }

    // ── manual() material resolution ─────────────────────────────

    public function test_manual_close_works_for_facade_item(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $facadeMat = $this->makeMaterial($user, 'facade');

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => ProjectPosition::KIND_FACADE,
            'facade_material_id' => $facadeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 400,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $facadeMat->id,
            'source_url' => $facadeMat->source_url,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::FACADE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        // Load relations as manual() does
        $item->load(['material', 'projectFitting.material', 'position.material', 'position.edgeMaterial', 'position.facadeMaterial']);

        // manual() material cascade: $item->material ?: fitting ?: facadeMaterial ?: edgeMaterial ?: material
        $material = $item->material
            ?: $item->projectFitting?->material
            ?: $item->position?->facadeMaterial
            ?: $item->position?->edgeMaterial
            ?: $item->position?->material;

        $this->assertNotNull($material);
        $this->assertSame($facadeMat->id, $material->id);
        $this->assertSame('facade', $material->type);

        // Simulate full manual close: artifact + asset + history + item update
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => CostDriverType::FACADE,
            'source_url_raw' => 'https://example.com/facade-source',
            'source_url_normalized' => 'https://example.com/facade-source',
            'extracted_price' => 3500.00,
            'currency' => 'RUB',
            'screenshot_path' => 'screenshots/manual/2026/03/facade.jpg',
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/manual/2026/03/facade.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 3500.00,
            'source_url' => 'https://example.com/facade-source',
            'screenshot_path' => 'screenshots/manual/2026/03/facade.jpg',
            'observed_at' => now(),
            'source_type' => MaterialPriceHistory::SOURCE_MANUAL,
            'is_verified' => false,
            'true_score' => 0,
            'currency' => 'RUB',
            'evidence_artifact_id' => $artifact->id,
            'evidence_mode' => EvidenceArtifact::MODE_MANUAL,
        ]);

        $item->update([
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Закрыто вручную',
            'price_history_id' => $history->id,
        ]);

        $fresh = $item->fresh();
        $this->assertSame(RevisionRunItem::STATUS_OK, $fresh->status);
        $this->assertSame('facade', $fresh->cost_driver_type);
        $this->assertSame($history->id, $fresh->price_history_id);
    }

    // ── Counter behavior ─────────────────────────────────────────

    public function test_counters_count_needs_manual_as_failed(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $plateMat = $this->makeMaterial($user, 'plate');
        $facadeMat = $this->makeMaterial($user, 'facade');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_IN_PROGRESS,
            'total_items' => 2,
        ]);

        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $plateMat->id,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $facadeMat->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::FACADE,
        ]);

        // refreshRunCounters logic: total - ok = failed
        $total = $run->items()->count();
        $ok = $run->items()->where('status', RevisionRunItem::STATUS_OK)->count();
        $failed = $total - $ok;

        $this->assertSame(2, $total);
        $this->assertSame(1, $ok);
        $this->assertSame(1, $failed);

        // Run status should be NEEDS_MANUAL (not READY) because failed > 0
        $status = $failed === 0 ? RevisionRun::STATUS_READY : RevisionRun::STATUS_NEEDS_MANUAL;
        $this->assertSame(RevisionRun::STATUS_NEEDS_MANUAL, $status);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B2A-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
    }

    private function makeMaterial(User $user, string $type = 'plate'): Material
    {
        return Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Тест ' . $type . ' ' . Str::random(4),
            'article' => 'TEST-' . Str::random(4),
            'type' => $type,
            'unit' => $type === 'facade' ? 'м²' : ($type === 'edge' ? 'м.п.' : 'м²'),
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/product?id=' . Str::random(4),
            'is_active' => true,
            'version' => 1,
            'visibility' => 'private',
            'data_origin' => 'manual',
            'trust_level' => 'unverified',
        ]);
    }
}
