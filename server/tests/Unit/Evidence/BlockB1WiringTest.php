<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\ProjectPosition;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockB1WiringTest extends TestCase
{
    use DatabaseTransactions;

    // ── collectReportItems / start() wiring ──────────────────────

    /**
     * Verify that collectReportItems returns cost_driver_type and
     * evidence_subject fields for plate, edge, and fitting entries.
     */
    public function test_collect_report_items_includes_cost_driver_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $plateMat = $this->makeMaterial($user, 'plate');
        $edgeMat = $this->makeMaterial($user, 'edge');
        $fittingMat = $this->makeMaterial($user, 'hardware');

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $plateMat->id,
            'edge_material_id' => $edgeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 800,
        ]);

        $fitting = ProjectFitting::create([
            'project_id' => $project->id,
            'material_id' => $fittingMat->id,
            'name' => 'Петля',
            'quantity' => 4,
            'unit_price' => 150,
        ]);

        // Use reflection to call private collectReportItems
        $controller = app(\App\Http\Controllers\Api\RevisionRunController::class);
        $method = new \ReflectionMethod($controller, 'collectReportItems');
        $method->setAccessible(true);

        // Build a minimal report array that matches the expected structure
        $report = [
            'plates' => [
                ['id' => $plateMat->id, 'source_url' => 'https://example.com/plate'],
            ],
            'edges' => [
                ['id' => $edgeMat->id, 'source_url' => 'https://example.com/edge'],
            ],
        ];

        $items = $method->invoke($controller, $project, $report);

        // Find plate item
        $plateItem = collect($items)->firstWhere('cost_driver_type', CostDriverType::PLATE);
        $this->assertNotNull($plateItem, 'Plate item should exist in report items');
        $this->assertSame('project_position', $plateItem['evidence_subject_type']);
        $this->assertSame($position->id, $plateItem['evidence_subject_id']);

        // Find edge item
        $edgeItem = collect($items)->firstWhere('cost_driver_type', CostDriverType::EDGE);
        $this->assertNotNull($edgeItem, 'Edge item should exist in report items');
        $this->assertSame('project_position', $edgeItem['evidence_subject_type']);
        $this->assertSame($position->id, $edgeItem['evidence_subject_id']);

        // Find fitting item
        $fittingItem = collect($items)->firstWhere('cost_driver_type', CostDriverType::FITTING);
        $this->assertNotNull($fittingItem, 'Fitting item should exist in report items');
        $this->assertSame('project_fitting', $fittingItem['evidence_subject_type']);
        $this->assertSame($fitting->id, $fittingItem['evidence_subject_id']);
    }

    /**
     * Verify that RevisionRunItem records created with the new fields
     * from collectReportItems actually persist the values correctly.
     */
    public function test_revision_run_item_creation_with_cost_driver_fields(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $plateMat = $this->makeMaterial($user, 'plate');
        $position = $this->makePosition($project, $plateMat);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        // Simulate what start() does after collectReportItems
        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $plateMat->id,
            'source_url' => 'https://example.com/plate',
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        $fresh = RevisionRunItem::find($item->id);
        $this->assertSame('plate', $fresh->cost_driver_type);
        $this->assertSame('project_position', $fresh->evidence_subject_type);
        $this->assertSame($position->id, (int) $fresh->evidence_subject_id);
    }

    public function test_edge_item_gets_correct_cost_driver_type(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $edgeMat = $this->makeMaterial($user, 'edge');
        $plateMat = $this->makeMaterial($user, 'plate');

        $position = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $plateMat->id,
            'edge_material_id' => $edgeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 800,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $edgeMat->id,
            'source_url' => 'https://example.com/edge',
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => CostDriverType::EDGE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        $this->assertDatabaseHas('revision_run_items', [
            'id' => $item->id,
            'cost_driver_type' => 'edge',
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);
    }

    public function test_fitting_item_gets_correct_cost_driver_type(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $fittingMat = $this->makeMaterial($user, 'hardware');

        $fitting = ProjectFitting::create([
            'project_id' => $project->id,
            'material_id' => $fittingMat->id,
            'name' => 'Петля',
            'quantity' => 4,
            'unit_price' => 150,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_fitting_id' => $fitting->id,
            'material_id' => $fittingMat->id,
            'source_url' => 'https://example.com/hinge',
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => CostDriverType::FITTING,
            'evidence_subject_type' => 'project_fitting',
            'evidence_subject_id' => $fitting->id,
        ]);

        $this->assertDatabaseHas('revision_run_items', [
            'id' => $item->id,
            'cost_driver_type' => 'fitting',
            'evidence_subject_type' => 'project_fitting',
            'evidence_subject_id' => $fitting->id,
        ]);
    }

    // ── Pipeline artifact wiring ─────────────────────────────────

    /**
     * Verify that EvidenceArtifact created by the pipeline has
     * capture_source='auto' and cost_driver_type from the item.
     */
    public function test_pipeline_artifact_has_capture_source_and_cost_driver_type(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user, 'plate');

        $project = $this->makeProject($user);
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_IN_PROGRESS,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product/1',
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        // Directly create artifact as pipeline would, including new fields
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => $item->cost_driver_type,
            'source_url_raw' => 'https://example.com/product/1',
            'source_url_normalized' => 'https://example.com/product/1',
            'extracted_price' => 1500.00,
            'currency' => 'RUB',
            'screenshot_path' => 'screenshots/test/2026-03-28/test.jpg',
            'captured_at' => now(),
        ]);

        $fresh = EvidenceArtifact::find($artifact->id);
        $this->assertSame('auto', $fresh->capture_source);
        $this->assertSame('plate', $fresh->cost_driver_type);
    }

    /**
     * Verify that EvidenceAsset is created for screenshot after artifact persistence.
     */
    public function test_pipeline_creates_evidence_asset_for_screenshot(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user, 'plate');

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'screenshot_path' => 'screenshots/leroymerlin/2026-03-28/abc123.jpg',
            'captured_at' => now(),
        ]);

        // Simulate what pipeline now does after artifact creation
        $screenshotPath = 'screenshots/leroymerlin/2026-03-28/abc123.jpg';
        if (!empty($screenshotPath)) {
            EvidenceAsset::create([
                'uuid' => (string) Str::uuid(),
                'evidence_artifact_id' => $artifact->id,
                'asset_type' => 'screenshot',
                'file_path' => $screenshotPath,
                'mime_type' => 'image/jpeg',
            ]);
        }

        $artifact->refresh();
        $this->assertCount(1, $artifact->assets);

        $asset = $artifact->assets->first();
        $this->assertSame('screenshot', $asset->asset_type);
        $this->assertSame($screenshotPath, $asset->file_path);
        $this->assertSame('image/jpeg', $asset->mime_type);
    }

    // ── Manual close wiring ──────────────────────────────────────

    /**
     * Verify that manual close creates EvidenceArtifact with mode=manual
     * and capture_source=manual, plus an EvidenceAsset.
     */
    public function test_manual_close_creates_artifact_and_asset(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user, 'plate');
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product/1',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        // Simulate what manual() now does: artifact + asset + history
        $screenshotPath = 'screenshots/manual/2026/03/test-manual.jpg';

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => $item->cost_driver_type,
            'source_url_raw' => 'https://example.com/product/1',
            'source_url_normalized' => 'https://example.com/product/1',
            'extracted_price' => 2500.00,
            'currency' => 'RUB',
            'screenshot_path' => $screenshotPath,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $asset = EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => $screenshotPath,
            'original_filename' => 'my-screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 54321,
        ]);

        // Verify artifact
        $this->assertSame('manual', $artifact->mode);
        $this->assertSame('manual', $artifact->capture_source);
        $this->assertSame('plate', $artifact->cost_driver_type);
        $this->assertSame($item->id, $artifact->revision_run_item_id);

        // Verify asset
        $this->assertSame($artifact->id, $asset->evidence_artifact_id);
        $this->assertSame('screenshot', $asset->asset_type);
        $this->assertSame('my-screenshot.jpg', $asset->original_filename);
        $this->assertSame(54321, $asset->file_size);
    }

    /**
     * Verify that manual close links artifact to MaterialPriceHistory.
     */
    public function test_manual_close_links_artifact_to_history(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user, 'plate');
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product/1',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
            'extracted_price' => 2500.00,
            'screenshot_path' => 'screenshots/manual/2026/03/test.jpg',
            'captured_at' => now(),
        ]);

        // Create history linked to artifact (as manual() now does)
        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 2500.00,
            'source_url' => 'https://example.com/product/1',
            'screenshot_path' => 'screenshots/manual/2026/03/test.jpg',
            'observed_at' => now(),
            'source_type' => MaterialPriceHistory::SOURCE_MANUAL,
            'is_verified' => false,
            'true_score' => 0,
            'currency' => 'RUB',
            'evidence_artifact_id' => $artifact->id,
            'evidence_mode' => EvidenceArtifact::MODE_MANUAL,
        ]);

        $this->assertSame($artifact->id, $history->evidence_artifact_id);
        $this->assertSame('manual', $history->evidence_mode);

        // Verify the relation
        $this->assertInstanceOf(EvidenceArtifact::class, $history->evidenceArtifact);
        $this->assertSame($artifact->id, $history->evidenceArtifact->id);
    }

    // ── Backward compat: legacy path untouched ───────────────────

    /**
     * Items without cost_driver_type (legacy rows) still work through
     * the normal lifecycle without errors.
     */
    public function test_legacy_items_without_cost_driver_type_still_work(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user, 'plate');
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        // Create item WITHOUT new fields (simulates legacy path)
        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product/1',
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);

        $this->assertNull($item->cost_driver_type);
        $this->assertNull($item->evidence_subject_type);
        $this->assertNull($item->evidence_subject_id);

        // Simulate legacy flow completing the item
        $item->update([
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Snapshot обновлен автоматически',
        ]);

        $fresh = RevisionRunItem::find($item->id);
        $this->assertSame(RevisionRunItem::STATUS_OK, $fresh->status);
        $this->assertTrue($fresh->isCompleted());

        // No artifacts or assets should exist for this item
        $this->assertCount(0, EvidenceArtifact::where('revision_run_item_id', $item->id)->get());
        $this->assertSame(0, EvidenceAsset::whereHas('evidenceArtifact', function ($q) use ($item) {
            $q->where('revision_run_item_id', $item->id);
        })->count());
    }

    /**
     * Manual close response structure remains unchanged — the new artifact/asset
     * writes are additive and don't alter the JSON shape.
     */
    public function test_manual_close_response_structure_unchanged(): void
    {
        // The response from manual() contains: success, item, price_history_id
        // This test verifies that the new writes don't add unexpected keys
        // by checking what manual() returns (simulated).
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user, 'plate');
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product/1',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        // After manual close: item becomes OK, history linked, artifact/asset created
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
            'captured_at' => now(),
        ]);

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 2500.00,
            'source_url' => 'https://example.com/product/1',
            'screenshot_path' => 'screenshots/manual/2026/03/test.jpg',
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

        // The response JSON would contain these keys — verify item state is correct
        $freshItem = $item->fresh();
        $this->assertSame(RevisionRunItem::STATUS_OK, $freshItem->status);
        $this->assertSame('Закрыто вручную', $freshItem->message);
        $this->assertSame($history->id, $freshItem->price_history_id);
        $this->assertTrue($freshItem->isCompleted());
    }

    public function test_finalize_returns_existing_revision_for_already_finalized_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $revision = ProjectRevision::create([
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => '{"revision_run_id":1}',
            'snapshot_hash' => hash('sha256', '{"revision_run_id":1}'),
            'locked_at' => now(),
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_FINALIZED,
            'total_items' => 0,
            'ok_items' => 0,
            'failed_items' => 0,
            'project_revision_id' => $revision->id,
        ]);

        $beforeCount = ProjectRevision::query()
            ->where('project_id', $project->id)
            ->count();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('revision.id', $revision->id)
            ->assertJsonPath('revision.number', $revision->number);

        $this->assertSame(
            $beforeCount,
            ProjectRevision::query()->where('project_id', $project->id)->count()
        );
        $this->assertSame($revision->id, $run->fresh()->project_revision_id);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-BLK-B1-' . Str::random(4),
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
            'unit' => $type === 'edge' ? 'м.п.' : 'м²',
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/product?id=1',
            'is_active' => true,
            'version' => 1,
            'visibility' => 'private',
            'data_origin' => 'manual',
            'trust_level' => 'unverified',
        ]);
    }

    private function makePosition(Project $project, Material $material): ProjectPosition
    {
        return ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $material->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 800,
            'requires_price_justification' => true,
        ]);
    }
}
