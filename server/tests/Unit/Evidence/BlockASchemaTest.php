<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\ProjectPosition;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockASchemaTest extends TestCase
{
    use DatabaseTransactions;

    // ── Enum tests ───────────────────────────────────────────────

    public function test_cost_driver_type_all_returns_seven_values(): void
    {
        $all = CostDriverType::all();
        $this->assertCount(7, $all);
        $this->assertContains('plate', $all);
        $this->assertContains('edge', $all);
        $this->assertContains('facade', $all);
        $this->assertContains('fitting', $all);
        $this->assertContains('operation', $all);
        $this->assertContains('labor_work', $all);
        $this->assertContains('expense', $all);
    }

    public function test_capture_source_all_returns_four_values(): void
    {
        $all = CaptureSource::all();
        $this->assertCount(4, $all);
        $this->assertContains('auto', $all);
        $this->assertContains('manual', $all);
        $this->assertContains('chrome_ext', $all);
        $this->assertContains('internal', $all);
    }

    public function test_cost_driver_type_requires_url_subset(): void
    {
        $subset = CostDriverType::requiresUrl();
        $this->assertContains('plate', $subset);
        $this->assertContains('fitting', $subset);
        $this->assertNotContains('operation', $subset);
        $this->assertNotContains('expense', $subset);
    }

    // ── Morph map tests ──────────────────────────────────────────

    public function test_morph_map_is_registered(): void
    {
        $map = Relation::morphMap();
        $this->assertArrayHasKey('project_position', $map);
        $this->assertArrayHasKey('project_fitting', $map);
        $this->assertArrayHasKey('operation', $map);
        $this->assertArrayHasKey('project_labor_work', $map);
        $this->assertArrayHasKey('expense', $map);
    }

    // ── RevisionRunItem model tests ──────────────────────────────

    public function test_revision_run_item_fillable_includes_new_columns(): void
    {
        $item = new RevisionRunItem();
        $fillable = $item->getFillable();
        $this->assertContains('cost_driver_type', $fillable);
        $this->assertContains('evidence_subject_type', $fillable);
        $this->assertContains('evidence_subject_id', $fillable);
    }

    public function test_revision_run_item_can_be_created_with_cost_driver_type(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
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

        $this->assertDatabaseHas('revision_run_items', [
            'id' => $item->id,
            'cost_driver_type' => 'plate',
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => $position->id,
        ]);
    }

    public function test_revision_run_item_evidence_subject_morph_to(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
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

        $loaded = RevisionRunItem::with('evidenceSubject')->find($item->id);
        $this->assertInstanceOf(ProjectPosition::class, $loaded->evidenceSubject);
        $this->assertEquals($position->id, $loaded->evidenceSubject->id);
    }

    public function test_revision_run_item_without_new_columns_still_works(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        // Create item WITHOUT new columns — backward compat
        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product/1',
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);

        $this->assertNotNull($item->id);
        $this->assertNull($item->evidence_subject_type);
        $this->assertNull($item->evidence_subject_id);
        // Backfill sets cost_driver_type, but a fresh create without it yields NULL
        // (backfill only runs once in the migration, not on new inserts)
        $this->assertTrue($item->isCompleted() === false);
    }

    // ── EvidenceArtifact model tests ─────────────────────────────

    public function test_evidence_artifact_fillable_includes_new_columns(): void
    {
        $artifact = new EvidenceArtifact();
        $fillable = $artifact->getFillable();
        $this->assertContains('capture_source', $fillable);
        $this->assertContains('cost_driver_type', $fillable);
    }

    public function test_evidence_artifact_can_be_created_with_null_material_id(): void
    {
        $user = User::factory()->create();

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => null,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::EXPENSE,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->assertNotNull($artifact->id);
        $this->assertNull($artifact->material_id);
        $this->assertSame('internal', $artifact->capture_source);
        $this->assertSame('expense', $artifact->cost_driver_type);
    }

    public function test_evidence_artifact_with_material_id_still_works(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user);

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'source_url_raw' => 'https://example.com/product/1',
            'extracted_price' => 1500.00,
            'captured_at' => now(),
        ]);

        $this->assertNotNull($artifact->id);
        $this->assertEquals($material->id, $artifact->material_id);
        $this->assertInstanceOf(Material::class, $artifact->material);
    }

    // ── EvidenceAsset model tests ────────────────────────────────

    public function test_evidence_asset_can_be_created_and_linked(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user);

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'captured_at' => now(),
        ]);

        $asset = EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'evidence/screenshots/2026/03/test.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 123456,
        ]);

        $this->assertNotNull($asset->id);
        $this->assertInstanceOf(EvidenceArtifact::class, $asset->evidenceArtifact);
    }

    public function test_evidence_artifact_has_many_assets(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user);

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'captured_at' => now(),
        ]);

        EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'evidence/screenshots/2026/03/shot.jpg',
        ]);
        EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'document',
            'file_path' => 'evidence/documents/2026/03/receipt.pdf',
        ]);

        $artifact->refresh();
        $this->assertCount(2, $artifact->assets);
        $this->assertSame('screenshot', $artifact->assets->first()->asset_type);
    }

    public function test_evidence_asset_cascade_deletes_with_artifact(): void
    {
        $user = User::factory()->create();
        $material = $this->makeMaterial($user);

        $artifact = EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'captured_at' => now(),
        ]);

        EvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'evidence/screenshots/2026/03/shot.jpg',
        ]);

        $artifactId = $artifact->id;
        $artifact->delete();

        $this->assertDatabaseMissing('evidence_assets', [
            'evidence_artifact_id' => $artifactId,
        ]);
    }

    // ── Backward compat: legacy isCompleted still works ──────────

    public function test_legacy_is_completed_logic_unchanged(): void
    {
        $item = new RevisionRunItem();

        $item->status = RevisionRunItem::STATUS_OK;
        $this->assertTrue($item->isCompleted());

        $item->status = RevisionRunItem::STATUS_NEEDS_MANUAL;
        $item->state = RevisionRunItem::STATE_AUTO_VERIFIED;
        $this->assertTrue($item->isCompleted());

        $item->status = RevisionRunItem::STATUS_NEEDS_MANUAL;
        $item->state = RevisionRunItem::STATE_MANUAL_VERIFIED;
        $this->assertTrue($item->isCompleted());

        $item->status = RevisionRunItem::STATUS_NEEDS_MANUAL;
        $item->state = RevisionRunItem::STATE_FAILED;
        $this->assertFalse($item->isCompleted());
    }

    // ── Backfill correctness tests ─────────────────────────────

    public function test_backfill_classifies_plate_edge_facade_fitting_correctly(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $plateMat = $this->makeMaterial($user, 'plate');
        $edgeMat = $this->makeMaterial($user, 'edge');
        $facadeMat = $this->makeMaterial($user, 'facade');
        $fittingMat = $this->makeMaterial($user, 'hardware');

        // Panel position with plate + edge materials
        $panelPos = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $plateMat->id,
            'edge_material_id' => $edgeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 800,
        ]);

        // Facade position with facade material
        $facadePos = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'facade',
            'facade_material_id' => $facadeMat->id,
            'quantity' => 1,
            'width' => 600,
            'length' => 800,
        ]);

        // Fitting
        $fitting = ProjectFitting::create([
            'project_id' => $project->id,
            'material_id' => $fittingMat->id,
            'name' => 'Test Fitting',
            'quantity' => 4,
            'unit_price' => 150,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 4,
        ]);

        // Insert items with NULL cost_driver_type to simulate pre-backfill state
        $plateItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $panelPos->id,
            'material_id' => $plateMat->id,
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);
        $edgeItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $panelPos->id,
            'material_id' => $edgeMat->id,
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);
        $facadeItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $facadePos->id,
            'material_id' => $facadeMat->id,
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);
        $fittingItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_fitting_id' => $fitting->id,
            'material_id' => $fittingMat->id,
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);

        // Wipe cost_driver_type to simulate pre-migration state
        DB::table('revision_run_items')
            ->whereIn('id', [$plateItem->id, $edgeItem->id, $facadeItem->id, $fittingItem->id])
            ->update(['cost_driver_type' => null]);

        // Run the corrective migration logic inline
        $this->runBackfillRepairLogic();

        $this->assertSame('plate', RevisionRunItem::find($plateItem->id)->cost_driver_type);
        $this->assertSame('edge', RevisionRunItem::find($edgeItem->id)->cost_driver_type);
        $this->assertSame('facade', RevisionRunItem::find($facadeItem->id)->cost_driver_type);
        $this->assertSame('fitting', RevisionRunItem::find($fittingItem->id)->cost_driver_type);
    }

    public function test_backfill_uses_material_type_fallback_for_orphan_edge(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $edgeMat = $this->makeMaterial($user, 'edge');
        $otherPlateMat = $this->makeMaterial($user, 'plate');

        // Position where edge_material_id does NOT match our edge material (simulates FK change)
        $pos = ProjectPosition::create([
            'project_id' => $project->id,
            'kind' => 'panel',
            'material_id' => $otherPlateMat->id,
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

        // Item with edge material but position no longer points to it
        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $pos->id,
            'material_id' => $edgeMat->id,
            'status' => RevisionRunItem::STATUS_PENDING,
        ]);

        DB::table('revision_run_items')
            ->where('id', $item->id)
            ->update(['cost_driver_type' => null]);

        $this->runBackfillRepairLogic();

        $this->assertSame('edge', RevisionRunItem::find($item->id)->cost_driver_type);
    }

    public function test_backfill_is_idempotent(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $plateMat = $this->makeMaterial($user, 'plate');

        $pos = $this->makePosition($project, $plateMat);
        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_PENDING,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $pos->id,
            'material_id' => $plateMat->id,
            'status' => RevisionRunItem::STATUS_PENDING,
            'cost_driver_type' => 'plate',
        ]);

        // Run repair on already-correct data — should not change anything
        $this->runBackfillRepairLogic();

        $this->assertSame('plate', RevisionRunItem::find($item->id)->cost_driver_type);
    }

    /**
     * Replays the corrective backfill logic from
     * 2026_03_28_100001_fix_cost_driver_type_backfill.
     */
    private function runBackfillRepairLogic(): void
    {
        DB::table('revision_run_items')
            ->whereNotNull('project_fitting_id')
            ->where('cost_driver_type', '!=', 'fitting')
            ->orWhere(function ($q) {
                $q->whereNotNull('project_fitting_id')->whereNull('cost_driver_type');
            })
            ->update(['cost_driver_type' => 'fitting']);

        DB::statement("
            UPDATE revision_run_items rri
            JOIN project_positions pp ON pp.id = rri.project_position_id
            SET rri.cost_driver_type = 'edge'
            WHERE rri.project_position_id IS NOT NULL
              AND pp.edge_material_id IS NOT NULL
              AND rri.material_id = pp.edge_material_id
              AND (rri.cost_driver_type != 'edge' OR rri.cost_driver_type IS NULL)
        ");

        DB::statement("
            UPDATE revision_run_items rri
            JOIN project_positions pp ON pp.id = rri.project_position_id
            SET rri.cost_driver_type = 'facade'
            WHERE rri.project_position_id IS NOT NULL
              AND pp.facade_material_id IS NOT NULL
              AND rri.material_id = pp.facade_material_id
              AND (rri.cost_driver_type != 'facade' OR rri.cost_driver_type IS NULL)
        ");

        DB::statement("
            UPDATE revision_run_items rri
            JOIN project_positions pp ON pp.id = rri.project_position_id
            SET rri.cost_driver_type = 'plate'
            WHERE rri.project_position_id IS NOT NULL
              AND pp.material_id IS NOT NULL
              AND rri.material_id = pp.material_id
              AND (rri.cost_driver_type != 'plate' OR rri.cost_driver_type IS NULL)
        ");

        DB::statement("
            UPDATE revision_run_items rri
            JOIN materials m ON m.id = rri.material_id
            SET rri.cost_driver_type = m.type
            WHERE m.type IN ('edge', 'facade')
              AND (rri.cost_driver_type != m.type OR rri.cost_driver_type IS NULL)
              AND rri.project_fitting_id IS NULL
        ");

        DB::table('revision_run_items')
            ->whereNull('cost_driver_type')
            ->update(['cost_driver_type' => 'plate']);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-BLK-A-' . Str::random(4),
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
