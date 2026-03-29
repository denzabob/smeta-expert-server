<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\Expense;
use App\Models\Material;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use App\Services\SnapshotService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockB5bExpenseDocumentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    // ── 1. Flag off → rejects upload ──────────────────────────

    public function test_flag_off_rejects_document_upload(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => false]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(403);
        $this->assertStringContainsString('not enabled', $response->json('error'));
    }

    // ── 2. Rejects non-expense item ───────────────────────────

    public function test_rejects_non_expense_item(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B5b-NE-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id, 'origin' => 'user',
            'name' => 'ДСП Тест', 'article' => 'DSP-1', 'type' => 'plate',
            'unit' => 'м²', 'price_per_unit' => 1000, 'is_active' => true,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_IN_PROGRESS,
            'total_items' => 1,
        ]);

        $plateItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/dsp',
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
            'evidence_subject_type' => 'project_position',
            'evidence_subject_id' => 1,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'material_id' => $material->id,
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $plateItem->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'captured_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$plateItem->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('expense items', $response->json('error'));
    }

    // ── 3. Rejects finalized run ──────────────────────────────

    public function test_rejects_finalized_run(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();
        $run->update(['status' => RevisionRun::STATUS_FINALIZED]);

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('finalized', $response->json('error'));
    }

    // ── 4. Rejects missing file ───────────────────────────────

    public function test_rejects_missing_file(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document_file');
    }

    // ── 5. Rejects invalid mime type ──────────────────────────

    public function test_rejects_invalid_mime_type(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('data.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('document_file');
    }

    // ── 6. Successful upload creates EvidenceAsset ────────────

    public function test_successful_upload_creates_evidence_asset(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();

        $file = UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf');

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => $file,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'asset' => ['id', 'uuid', 'asset_type', 'mime_type', 'original_filename', 'file_size'],
            'trust_score',
        ]);
        $this->assertTrue($response->json('success'));
        $this->assertEquals('document', $response->json('asset.asset_type'));
        $this->assertEquals('receipt.pdf', $response->json('asset.original_filename'));

        // Verify DB
        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();
        $asset = EvidenceAsset::where('evidence_artifact_id', $artifact->id)
            ->where('asset_type', 'document')
            ->first();
        $this->assertNotNull($asset);
        $this->assertEquals('application/pdf', $asset->mime_type);
        $this->assertNotEmpty($asset->file_path);
        $this->assertNotEmpty($asset->uuid);

        // Verify file stored
        Storage::disk('public')->assertExists($asset->file_path);

        // Item status unchanged
        $item->refresh();
        $this->assertEquals(RevisionRunItem::STATUS_OK, $item->status);
    }

    // ── 7. Upload bumps trust_score to 75 ─────────────────────

    public function test_upload_bumps_trust_score_to_75(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();

        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();
        $this->assertEquals(50, $artifact->trust_score, 'Initial trust_score should be 50');

        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->image('photo.jpg', 100, 100),
            ]);

        $response->assertStatus(200);
        $this->assertEquals(75, $response->json('trust_score'));

        $artifact->refresh();
        $this->assertEquals(75, $artifact->trust_score);
    }

    // ── 8. Second upload keeps trust_score at 75 ──────────────

    public function test_second_upload_keeps_trust_score_at_75(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, , $run, $item] = $this->makeRunWithExpenseItem();

        // First upload
        $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('receipt1.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(200);

        $artifact = EvidenceArtifact::where('revision_run_item_id', $item->id)->first();
        $this->assertEquals(75, $artifact->trust_score);

        // Second upload
        $response = $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('receipt2.pdf', 150, 'application/pdf'),
            ]);

        $response->assertStatus(200);
        $this->assertEquals(75, $response->json('trust_score'));

        // Two document assets
        $docAssets = EvidenceAsset::where('evidence_artifact_id', $artifact->id)
            ->where('asset_type', 'document')
            ->count();
        $this->assertEquals(2, $docAssets);

        $artifact->refresh();
        $this->assertEquals(75, $artifact->trust_score, 'trust_score should stay at 75');
    }

    // ── 9. show() includes document asset ─────────────────────

    public function test_show_includes_document_asset(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, $project, $run, $item] = $this->makeRunWithExpenseItem();

        // Attach a document
        $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(200);

        // Call show()
        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);

        $items = collect($response->json('items'));
        $expItem = $items->firstWhere('cost_driver_type', CostDriverType::EXPENSE);
        $this->assertNotNull($expItem);
        $this->assertTrue($expItem['has_evidence']);

        $artifacts = $expItem['evidence_artifacts'] ?? [];
        $this->assertNotEmpty($artifacts);

        $assets = $artifacts[0]['assets'] ?? [];
        $docAsset = collect($assets)->firstWhere('asset_type', 'document');
        $this->assertNotNull($docAsset, 'Document asset should appear in show() response');
        $this->assertEquals('application/pdf', $docAsset['mime_type']);
    }

    // ── 10. finalize() reflects bumped trust_score ────────────

    public function test_finalize_reflects_bumped_trust_score(): void
    {
        config(['smeta.evidence.expenses_document_enabled' => true]);

        [$user, $project, $run, $item] = $this->makeRunWithExpenseItem();

        // Attach document (bumps trust_score to 75)
        $this->actingAs($user)
            ->postJson("/api/revisions/run/{$run->id}/items/{$item->id}/attach-document", [
                'document_file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(200);

        $run->update(['status' => RevisionRun::STATUS_READY]);

        $captured = null;
        $fakeRevision = new ProjectRevision();
        $fakeRevision->id = 99999;
        $fakeRevision->number = 1;

        $this->mock(SnapshotService::class, function ($mock) use (&$captured, $fakeRevision) {
            $mock->shouldReceive('createSnapshot')
                ->once()
                ->withArgs(function ($proj, $userId, $extra) use (&$captured) {
                    $captured = $extra;
                    return true;
                })
                ->andReturn($fakeRevision);
        });

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $response->assertStatus(200);

        $justifications = $captured['price_justifications'] ?? [];
        $expRow = collect($justifications)->firstWhere('cost_driver_type', CostDriverType::EXPENSE);

        $this->assertNotNull($expRow);
        $this->assertEquals(75, $expRow['true_score'], 'Finalize should reflect bumped trust_score');
        $this->assertEquals('user_declared', $expRow['source_type']);
        $this->assertEquals(CaptureSource::INTERNAL, $expRow['capture_source']);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function makeRunWithExpenseItem(): array
    {
        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-B5b-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $expense = Expense::create([
            'project_id' => $project->id,
            'name' => 'Доставка',
            'amount' => 3500,
            'description' => null,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_IN_PROGRESS,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => null,
            'source_url' => null,
            'status' => RevisionRunItem::STATUS_OK,
            'message' => 'Пользовательский расход: сумма задана вручную',
            'cost_driver_type' => CostDriverType::EXPENSE,
            'evidence_subject_type' => 'expense',
            'evidence_subject_id' => $expense->id,
        ]);

        EvidenceArtifact::create([
            'uuid' => (string) Str::uuid(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::INTERNAL,
            'cost_driver_type' => CostDriverType::EXPENSE,
            'extracted_price' => 3500.0,
            'currency' => 'RUB',
            'extracted_name' => $expense->name,
            'trust_score' => 50,
            'captured_at' => now(),
            'created_by' => $user->id,
        ]);

        return [$user, $project, $run, $item];
    }
}
