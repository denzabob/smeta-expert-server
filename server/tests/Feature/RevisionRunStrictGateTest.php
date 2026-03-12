<?php

namespace Tests\Feature;

use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\ProjectFitting;
use App\Models\ProjectPosition;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RevisionRunStrictGateTest extends TestCase
{
    use RefreshDatabase;

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-TEST-001',
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
    }

    private function makeMaterial(User $user): Material
    {
        return Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'ЛДСП Тест 2800x2070x16',
            'article' => 'TEST-001',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 1000,
            'source_url' => 'https://example.com/product?id=1&utm_source=ads',
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

    private function makeHardwareMaterial(User $user): Material
    {
        return Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Петля с доводчиком',
            'article' => 'HW-001',
            'type' => 'hardware',
            'unit' => 'шт',
            'price_per_unit' => 250,
            'source_url' => 'https://example.com/hardware/item-1',
            'is_active' => true,
            'version' => 1,
            'visibility' => 'private',
            'data_origin' => 'manual',
            'trust_level' => 'unverified',
        ]);
    }

    public function test_finalize_returns_409_when_non_ok_items_exist(): void
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
            'ok_items' => 0,
            'failed_items' => 1,
        ]);

        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product?id=1',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'message' => 'need manual',
        ]);

        $this->actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/revisions/run/{$run->id}/finalize");

        $response->assertStatus(409);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_manual_close_creates_snapshot_with_true_score_zero(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);
        $position = $this->makePosition($project, $material);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
            'ok_items' => 0,
            'failed_items' => 1,
        ]);

        $item = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'project_position_id' => $position->id,
            'material_id' => $material->id,
            'source_url' => 'https://example.com/product?id=1&utm_source=test',
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
        ]);

        $this->actingAs($user);
        $response = $this->post("/api/revisions/run/{$run->id}/items/{$item->id}/manual", [
            'price_per_unit' => 1234.56,
            'currency' => 'RUB',
            'source_url' => 'https://example.com/product?id=1&utm_source=test',
            'screenshot_file' => UploadedFile::fake()->image('proof.png', 1920, 1080),
        ]);

        $response->assertStatus(200);

        $item->refresh();
        $this->assertSame(RevisionRunItem::STATUS_OK, $item->status);
        $this->assertNotNull($item->price_history_id);

        $history = MaterialPriceHistory::findOrFail($item->price_history_id);
        $this->assertSame(MaterialPriceHistory::SOURCE_MANUAL, $history->source_type);
        $this->assertSame(0, $history->true_score);
        $this->assertNotNull($history->screenshot_path);
        Storage::disk('public')->assertExists($history->screenshot_path);
    }

    public function test_start_run_includes_hardware_fittings(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $hardware = $this->makeHardwareMaterial($user);

        $fitting = ProjectFitting::create([
            'project_id' => $project->id,
            'material_id' => $hardware->id,
            'name' => $hardware->name,
            'article' => $hardware->article,
            'unit' => 'шт',
            'quantity' => 4,
            'unit_price' => 250,
            'source_url' => $hardware->source_url,
        ]);

        $this->actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/revisions/run");

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('total_items', 1);

        $runId = (int) $response->json('run_id');
        $this->assertGreaterThan(0, $runId);

        $this->assertDatabaseHas('revision_run_items', [
            'revision_run_id' => $runId,
            'project_position_id' => null,
            'project_fitting_id' => $fitting->id,
            'material_id' => $hardware->id,
        ]);
    }
}
