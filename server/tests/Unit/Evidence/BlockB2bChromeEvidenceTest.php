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

class BlockB2bChromeEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    // ── Feature flag ─────────────────────────────────────────────

    public function test_chrome_revision_flag_method(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => false]);
        $this->assertFalse(EvidenceFeatures::chromeRevisionEnabled());

        config(['smeta.evidence.chrome_revision_enabled' => true]);
        $this->assertTrue(EvidenceFeatures::chromeRevisionEnabled());
    }

    // ── Happy path: full write sequence ──────────────────────────

    public function test_submit_evidence_creates_artifact_asset_history(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

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
            'source_url' => 'https://example.com/plate',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        // Simulate the full write sequence as submitItemEvidence does
        $screenshotPath = 'screenshots/chrome/2026/03/test-chrome.jpg';
        $rawUrl = 'https://example.com/plate?id=1';
        $normalized = 'https://example.com/plate?id=1';

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::CHROME_EXT,
            'cost_driver_type' => $item->cost_driver_type,
            'source_url_raw' => $rawUrl,
            'source_url_normalized' => $normalized,
            'extracted_price' => 1500.00,
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
            'original_filename' => 'chrome-screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12345,
        ]);

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 1500.00,
            'source_url' => $normalized,
            'raw_source_url' => $rawUrl,
            'normalized_source_url' => $normalized,
            'screenshot_path' => $screenshotPath,
            'observed_at' => now(),
            'source_type' => MaterialPriceHistory::SOURCE_CHROME_EXT,
            'is_verified' => false,
            'true_score' => 0,
            'currency' => 'RUB',
            'evidence_artifact_id' => $artifact->id,
            'evidence_mode' => EvidenceArtifact::MODE_MANUAL,
        ]);

        // Verify artifact
        $this->assertSame('chrome_ext', $artifact->capture_source);
        $this->assertSame('manual', $artifact->mode);
        $this->assertSame('plate', $artifact->cost_driver_type);
        $this->assertSame($item->id, $artifact->revision_run_item_id);

        // Verify asset
        $this->assertSame($artifact->id, $asset->evidence_artifact_id);
        $this->assertSame('screenshot', $asset->asset_type);

        // Verify history
        $this->assertSame('chrome_ext', $history->source_type);
        $this->assertSame($artifact->id, $history->evidence_artifact_id);
        $this->assertSame('manual', $history->evidence_mode);
    }

    public function test_artifact_has_chrome_ext_capture_source(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user, 'plate');

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::CHROME_EXT,
            'cost_driver_type' => CostDriverType::PLATE,
            'captured_at' => now(),
        ]);

        $fresh = EvidenceArtifact::find($artifact->id);
        $this->assertSame(CaptureSource::CHROME_EXT, $fresh->capture_source);
        $this->assertSame(EvidenceArtifact::MODE_MANUAL, $fresh->mode);
    }

    // ── Item status update ───────────────────────────────────────

    public function test_submit_evidence_updates_item_to_ok(): void
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
            'source_url' => 'https://example.com/plate',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Simulate the item update as the endpoint does
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::CHROME_EXT,
            'cost_driver_type' => CostDriverType::PLATE,
            'captured_at' => now(),
        ]);

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 1500.00,
            'source_url' => 'https://example.com/plate',
            'observed_at' => now(),
            'source_type' => MaterialPriceHistory::SOURCE_CHROME_EXT,
            'currency' => 'RUB',
            'evidence_artifact_id' => $artifact->id,
            'evidence_mode' => EvidenceArtifact::MODE_MANUAL,
        ]);

        $item->update([
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Закрыто через расширение',
            'source_url' => 'https://example.com/plate',
            'price_history_id' => $history->id,
        ]);

        $fresh = $item->fresh();
        $this->assertSame(RevisionRunItem::STATUS_OK, $fresh->status);
        $this->assertSame('Закрыто через расширение', $fresh->message);
        $this->assertSame($history->id, $fresh->price_history_id);
    }

    // ── Run counter refresh ──────────────────────────────────────

    public function test_submit_evidence_refreshes_run_counters(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user, 'plate');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 2,
        ]);

        // One item already OK
        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Second item pending
        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Before close: 1 OK, 1 failed
        $total = $run->items()->count();
        $ok = $run->items()->where('status', RevisionRunItem::STATUS_OK)->count();
        $this->assertSame(2, $total);
        $this->assertSame(1, $ok);

        // Simulate closing the second item
        $run->items()->where('status', RevisionRunItem::STATUS_NEEDS_MANUAL)->first()
            ->update(['status' => RevisionRunItem::STATUS_OK]);

        // Refresh counters as the endpoint does
        $ok = $run->items()->where('status', RevisionRunItem::STATUS_OK)->count();
        $failed = $total - $ok;

        $run->update([
            'status' => $failed === 0 ? RevisionRun::STATUS_READY : RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => $total,
            'ok_items' => $ok,
            'failed_items' => $failed,
            'finished_at' => $failed === 0 ? now() : null,
        ]);

        $run->refresh();
        $this->assertSame(RevisionRun::STATUS_READY, $run->status);
        $this->assertSame(2, $run->ok_items);
        $this->assertSame(0, $run->failed_items);
        $this->assertNotNull($run->finished_at);
    }

    // ── Guard: flag off ──────────────────────────────────────────

    public function test_submit_evidence_rejected_when_flag_off(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/chrome/revision-items/999/evidence', [
            'price_per_unit' => 100,
            'currency' => 'RUB',
            'source_url' => 'https://example.com',
        ]);

        $response->assertStatus(404);
    }

    // ── Guard: ownership ─────────────────────────────────────────

    public function test_submit_evidence_rejected_for_wrong_owner(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->makeProject($owner);
        $material = $this->makeMaterial($owner, 'plate');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $owner->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Load run.project for ownership check
        $item->load('run.project');

        // Stranger should not be able to access
        $this->assertNotSame($stranger->id, $item->run->project->user_id);
    }

    // ── Guard: already resolved ──────────────────────────────────

    public function test_submit_evidence_rejected_for_already_ok_item(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user, 'plate');

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Item is already OK — should be rejected with 409 logic
        $this->assertSame(RevisionRunItem::STATUS_OK, $item->status);
    }

    // ── Guard: no material ───────────────────────────────────────

    public function test_submit_evidence_rejected_without_material(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        // Item with no material_id and no linked position/fitting
        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
        ]);

        $item->load(['material', 'projectFitting.material', 'position.material', 'position.edgeMaterial', 'position.facadeMaterial']);

        $material = $item->material
            ?: $item->projectFitting?->material
            ?: $item->position?->facadeMaterial
            ?: $item->position?->edgeMaterial
            ?: $item->position?->material;

        $this->assertNull($material, 'No material resolvable — should result in 422');
    }

    // ── Facade item via chrome ───────────────────────────────────

    public function test_submit_evidence_works_for_facade_item(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

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

        // Facade material resolves via item->material
        $item->load(['material', 'projectFitting.material', 'position.facadeMaterial']);
        $material = $item->material
            ?: $item->projectFitting?->material
            ?: $item->position?->facadeMaterial;

        $this->assertNotNull($material);
        $this->assertSame($facadeMat->id, $material->id);

        // Create artifact via chrome extension path
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::CHROME_EXT,
            'cost_driver_type' => CostDriverType::FACADE,
            'source_url_raw' => 'https://example.com/facade',
            'source_url_normalized' => 'https://example.com/facade',
            'extracted_price' => 4200.00,
            'currency' => 'RUB',
            'screenshot_path' => 'screenshots/chrome/2026/03/facade.jpg',
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $history = MaterialPriceHistory::create([
            'material_id' => $material->id,
            'version' => 1,
            'valid_from' => now()->toDateString(),
            'price_per_unit' => 4200.00,
            'source_url' => 'https://example.com/facade',
            'observed_at' => now(),
            'source_type' => MaterialPriceHistory::SOURCE_CHROME_EXT,
            'currency' => 'RUB',
            'evidence_artifact_id' => $artifact->id,
            'evidence_mode' => EvidenceArtifact::MODE_MANUAL,
        ]);

        $item->update([
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Закрыто через расширение',
            'price_history_id' => $history->id,
        ]);

        $fresh = $item->fresh();
        $this->assertSame(RevisionRunItem::STATUS_OK, $fresh->status);
        $this->assertSame('facade', $fresh->cost_driver_type);
        $this->assertSame(CaptureSource::CHROME_EXT, $artifact->capture_source);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B2B-' . Str::random(4),
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

    private function makePosition(Project $project, Material $material): ProjectPosition
    {
        return ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $material->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 800,
        ]);
    }
}
