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
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockD1EvidenceVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    // ── show() includes evidence fields ──────────────────────────

    public function test_show_response_includes_cost_driver_type(): void
    {
        [$user, $project, $run] = $this->makeRunWithItem(
            costDriverType: CostDriverType::PLATE,
        );

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.cost_driver_type', 'plate');
    }

    public function test_show_response_includes_resolved_capture_source(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            costDriverType: CostDriverType::EDGE,
        );

        EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $item->material_id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::EDGE,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.resolved_capture_source', 'auto');
    }

    public function test_show_response_has_evidence_true_when_asset_exists(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            costDriverType: CostDriverType::PLATE,
        );

        $artifact = EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $item->material_id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        EvidenceAsset::create([
            'uuid' => Str::uuid()->toString(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'evidence/test.png',
            'original_filename' => 'test.png',
            'mime_type' => 'image/png',
            'file_size' => 1024,
            'sha256' => hash('sha256', 'test'),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.has_evidence', true);
    }

    public function test_show_response_has_evidence_false_when_no_asset(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            costDriverType: CostDriverType::FITTING,
        );

        // Artifact without any asset
        EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $item->material_id,
            'mode' => EvidenceArtifact::MODE_AUTO,
            'capture_source' => CaptureSource::AUTO,
            'cost_driver_type' => CostDriverType::FITTING,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.has_evidence', false);
    }

    public function test_show_response_nulls_when_no_artifact(): void
    {
        [$user, $project, $run] = $this->makeRunWithItem(
            costDriverType: CostDriverType::PLATE,
        );

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.resolved_capture_source', null);
        $response->assertJsonPath('items.0.has_evidence', false);
    }

    public function test_show_response_chrome_ext_capture_source(): void
    {
        [$user, $project, $run, $item] = $this->makeRunWithItem(
            costDriverType: CostDriverType::EDGE,
        );

        $artifact = EvidenceArtifact::create([
            'uuid' => Str::uuid()->toString(),
            'revision_run_id' => $run->id,
            'revision_run_item_id' => $item->id,
            'material_id' => $item->material_id,
            'mode' => EvidenceArtifact::MODE_MANUAL,
            'capture_source' => CaptureSource::CHROME_EXT,
            'cost_driver_type' => CostDriverType::EDGE,
        ]);

        EvidenceAsset::create([
            'uuid' => Str::uuid()->toString(),
            'evidence_artifact_id' => $artifact->id,
            'asset_type' => 'screenshot',
            'file_path' => 'evidence/chrome.png',
            'original_filename' => 'chrome.png',
            'mime_type' => 'image/png',
            'file_size' => 2048,
            'sha256' => hash('sha256', 'chrome'),
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/revisions/run/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('items.0.resolved_capture_source', 'chrome_ext');
        $response->assertJsonPath('items.0.has_evidence', true);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeRunWithItem(string $costDriverType): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-D1-' . Str::random(4),
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
            'cost_driver_type' => $costDriverType,
        ]);

        return [$user, $project, $run, $item];
    }
}
