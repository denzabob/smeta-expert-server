<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureSource;
use App\Evidence\CostDriverType;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\Material;
use App\Models\Project;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockD2EvidenceDetailTest extends TestCase
{
    use DatabaseTransactions;

    // ── show() returns widened artifact metadata ─────────────────

    public function test_show_response_includes_artifact_metadata(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem();

        $artifact = EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $item->material_id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::PLATE,
            'extracted_price' => 1250.50,
            'currency' => 'RUB',
            'source_url_raw' => 'https://example.com/product/123',
            'source_domain' => 'example.com',
            'captured_at' => now(),
        ]);

        EvidenceAsset::create([
            'uuid' => Str::uuid()->toString(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/manual/2026/03/test.png',
            'original_filename' => 'test.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'sha256' => hash('sha256', 'test'),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'items' => [
                '*' => [
                    'evidence_artifacts' => [
                        '*' => [
                            'id',
                            'revision_run_item_id',
                            'capture_source',
                            'mode',
                            'extracted_price',
                            'currency',
                            'source_url_raw',
                            'source_domain',
                            'captured_at',
                            'created_at',
                            'assets' => [
                                '*' => [
                                    'id',
                                    'evidence_artifact_id',
                                    'asset_type',
                                    'mime_type',
                                    'original_filename',
                                    'file_size',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertJsonPath('items.0.evidence_artifacts.0.extracted_price', '1250.50');
        $response->assertJsonPath('items.0.evidence_artifacts.0.currency', 'RUB');
        $response->assertJsonPath('items.0.evidence_artifacts.0.source_url_raw', 'https://example.com/product/123');
        $response->assertJsonPath('items.0.evidence_artifacts.0.source_domain', 'example.com');
        $response->assertJsonPath('items.0.evidence_artifacts.0.assets.0.asset_type', 'screenshot');
        $response->assertJsonPath('items.0.evidence_artifacts.0.assets.0.mime_type', 'image/png');
        $response->assertJsonPath('items.0.evidence_artifacts.0.assets.0.file_size', 2048);
    }

    // ── File endpoint: happy path ────────────────────────────────

    public function test_evidence_asset_file_returns_200(): void
    {
        Storage::disk('public')->put('screenshots/d2test/test.png', 'fake-image-content');

        [$user, , , , $asset] = $this->makeRunWithItemAndAsset(
            filePath: 'screenshots/d2test/test.png',
        );

        $response = $this->actingAs($user)
            ->get("/api/evidence-assets/{$asset->id}/file");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');

        Storage::disk('public')->delete('screenshots/d2test/test.png');
    }

    // ── File endpoint: wrong user ────────────────────────────────

    public function test_evidence_asset_file_403_for_wrong_user(): void
    {
        Storage::disk('public')->put('screenshots/d2test/forbidden.png', 'fake-image-content');

        [$owner, , , , $asset] = $this->makeRunWithItemAndAsset(
            filePath: 'screenshots/d2test/forbidden.png',
        );

        $stranger = User::factory()->create();

        $response = $this->actingAs($stranger)
            ->get("/api/evidence-assets/{$asset->id}/file");

        $response->assertStatus(403);

        Storage::disk('public')->delete('screenshots/d2test/forbidden.png');
    }

    // ── File endpoint: missing asset record ──────────────────────

    public function test_evidence_asset_file_404_for_missing_asset(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/api/evidence-assets/999999/file');

        $response->assertStatus(404);
    }

    // ── File endpoint: asset exists but file missing from disk ───

    public function test_evidence_asset_file_404_when_file_missing(): void
    {
        [$user, , , , $asset] = $this->makeRunWithItemAndAsset(
            filePath: 'screenshots/d2test/ghost.png',
        );

        // Don't create the file on disk

        $response = $this->actingAs($user)
            ->get("/api/evidence-assets/{$asset->id}/file");

        $response->assertStatus(404);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeRunWithItem(): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-D2-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);

        $material = Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Тест материал ' . Str::random(4),
            'article' => 'TEST-' . Str::random(4),
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/product',
            'is_active' => true,
        ]);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        return [$user, $project, $run, $item];
    }

    private function makeRunWithItemAndAsset(string $filePath): array
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem();

        $artifact = EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $item->material_id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        $asset = EvidenceAsset::create([
            'uuid' => Str::uuid()->toString(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => $filePath,
            'original_filename' => basename($filePath),
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'test'),
        ]);

        return [$user, $project, $run, $item, $asset];
    }
}
