<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CostDriverType;
use App\Evidence\EvidenceFeatures;
use App\Models\Material;
use App\Models\Project;
use App\Models\RevisionRun;
use App\Models\RevisionRunItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockC1ChromeRevisionListTest extends TestCase
{
    use DatabaseTransactions;

    // ── Feature flag ─────────────────────────────────────────────

    public function test_list_returns_404_when_flag_off(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/chrome/revision-items');

        $response->assertStatus(404);
    }

    // ── Happy path ───────────────────────────────────────────────

    public function test_list_returns_open_items_for_authenticated_user(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 2,
        ]);

        $item1 = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        $item2 = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_BLOCKED,
            'cost_driver_type' => CostDriverType::EDGE,
        ]);

        $response = $this->actingAs($user)->getJson('/api/chrome/revision-items');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'items');
        $response->assertJsonPath('total', 2);

        $response->assertJsonStructure([
            'items' => [
                '*' => [
                    'id',
                    'revision_run_id',
                    'cost_driver_type',
                    'status',
                    'material_name',
                    'material_id',
                    'source_url',
                    'project_name',
                    'run_status',
                ],
            ],
            'total',
        ]);
    }

    // ── Isolation: other users' items not visible ────────────────

    public function test_list_excludes_other_users_items(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->makeProject($owner);
        $material = $this->makeMaterial($owner);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $owner->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 1,
        ]);

        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // Stranger should see nothing
        $response = $this->actingAs($stranger)->getJson('/api/chrome/revision-items');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'items');
        $response->assertJsonPath('total', 0);
    }

    // ── Excludes OK items ────────────────────────────────────────

    public function test_list_excludes_already_ok_items(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);

        $run = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_NEEDS_MANUAL,
            'total_items' => 2,
        ]);

        // OK item — should be excluded
        RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_OK,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        // NEEDS_MANUAL — should be included
        $openItem = RevisionRunItem::create([
            'revision_run_id' => $run->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_NEEDS_MANUAL,
            'cost_driver_type' => CostDriverType::EDGE,
        ]);

        $response = $this->actingAs($user)->getJson('/api/chrome/revision-items');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.id', $openItem->id);
    }

    // ── Empty list when no open items ────────────────────────────

    public function test_list_returns_empty_when_no_open_items(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/chrome/revision-items');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'items');
        $response->assertJsonPath('total', 0);
    }

    // ── Excludes runs that are already READY/FINALIZED ───────────

    public function test_list_excludes_items_from_completed_runs(): void
    {
        config(['smeta.evidence.chrome_revision_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $material = $this->makeMaterial($user);

        // Run that is READY — its items should not appear even if status is BLOCKED
        $readyRun = RevisionRun::create([
            'project_id' => $project->id,
            'initiator_user_id' => $user->id,
            'status' => RevisionRun::STATUS_READY,
            'total_items' => 1,
        ]);

        RevisionRunItem::create([
            'revision_run_id' => $readyRun->id,
            'material_id' => $material->id,
            'status' => RevisionRunItem::STATUS_BLOCKED,
            'cost_driver_type' => CostDriverType::PLATE,
        ]);

        $response = $this->actingAs($user)->getJson('/api/chrome/revision-items');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'items');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-C1-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
    }

    private function makeMaterial(User $user): Material
    {
        return Material::create([
            'user_id' => $user->id,
            'origin' => 'user',
            'name' => 'Тест материал ' . Str::random(4),
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
}
