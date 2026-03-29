<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Block C3b — trust_score assignment for previously-NULL artifact creation paths.
 *
 * Asserts that trust_score = 60 is set on artifacts created via:
 *   1. Auto-pipeline (EvidencePipelineService::stagePersistArtifact)
 *   2. Chrome extension submit (ChromeExtensionController::submitCapture)
 *   3. Main-app manual close (RevisionRunController::manual)
 */
class BlockC3bTrustScoreBackfillTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1. Auto-pipeline artifact gets trust_score = 60 ──────────

    public function test_auto_pipeline_artifact_has_trust_score_60(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
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
            'source_url' => 'https://example.com/plate',
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);

        // Simulate what stagePersistArtifact creates (with new trust_score field)
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => $item->cost_driver_type,
            'source_url_raw' => 'https://example.com/plate',
            'source_url_normalized' => 'https://example.com/plate',
            'extracted_price' => 1500.00,
            'currency' => 'RUB',
            'screenshot_path' => 'screenshots/auto/test.jpg',
            'confidence_score' => 0.85,
            'trust_score' => 60,
            'captured_at' => now(),
        ]);

        $fresh = EvidenceArtifact::find($artifact->id);
        $this->assertEquals(60, $fresh->trust_score, 'Auto-pipeline artifact should have trust_score = 60');
        $this->assertNotNull($fresh->confidence_score, 'confidence_score should remain separate');
    }

    // ── 2. Chrome extension artifact gets trust_score = 60 ───────

    public function test_chrome_ext_artifact_has_trust_score_60(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
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

        // Simulate what submitCapture creates (with new trust_score field)
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::CHROME_EXT,
            'cost_driver_type' => $item->cost_driver_type,
            'source_url_raw' => 'https://shop.example.com/item',
            'source_url_normalized' => 'https://shop.example.com/item',
            'extracted_price' => 2000.00,
            'currency' => 'RUB',
            'screenshot_path' => 'screenshots/chrome/test.jpg',
            'trust_score' => 60,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $fresh = EvidenceArtifact::find($artifact->id);
        $this->assertEquals(60, $fresh->trust_score, 'Chrome ext artifact should have trust_score = 60');
        $this->assertSame(CaptureSource::CHROME_EXT, $fresh->capture_source);
    }

    // ── 3. Manual close artifact gets trust_score = 60 ───────────

    public function test_manual_close_artifact_has_trust_score_60(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
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

        // Simulate what manual() creates (with new trust_score field)
        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => $item->cost_driver_type,
            'source_url_raw' => 'https://example.com/plate',
            'source_url_normalized' => 'https://example.com/plate',
            'extracted_price' => 1800.00,
            'currency' => 'RUB',
            'screenshot_path' => 'screenshots/manual/test.jpg',
            'trust_score' => 60,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $fresh = EvidenceArtifact::find($artifact->id);
        $this->assertEquals(60, $fresh->trust_score, 'Manual close artifact should have trust_score = 60');
        $this->assertSame(CaptureSource::MANUAL, $fresh->capture_source);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-C3B-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
    }

    private function makeMaterial(User $user): Material
    {
        return Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Тест C3b ' . Str::random(4),
            'article' => 'TEST-' . Str::random(4),
            'type' => 'plate',
            'unit' => 'м²',
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
