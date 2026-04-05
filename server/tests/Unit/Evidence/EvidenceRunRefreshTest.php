<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\ResolutionType;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceRunRefreshTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Pending item auto-resolves on refresh when fresh proof exists
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_auto_resolves_pending_item_with_fresh_proof(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-refresh-resolve';

        // Create run with one PENDING item
        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Test Plate',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        // Create fresh proof AFTER run was created
        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now(),
            'created_by'          => $user->id,
        ]);
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/test/refresh-proof.jpg',
            'original_filename'  => 'refresh-proof.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'refresh-proof'),
        ]);

        // Call refresh endpoint
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('auto_resolved', 1);

        // Item must now be RESOLVED with AUTO_FRESH
        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertSame(ResolutionType::AUTO_FRESH, $item->resolution_type);
        $this->assertSame($record->id, $item->evidence_record_id);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Refresh does not touch already-resolved items
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_does_not_change_already_resolved_items(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-already-resolved-refresh';

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 1,
            'failed_items'    => 0,
        ]);

        $existingRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now(),
            'created_by'          => $user->id,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_run_id'    => $run->id,
            'cost_component'     => CostComponent::PLATE,
            'label'              => 'Already Resolved Plate',
            'status'             => EvidenceItemStatus::RESOLVED,
            'resolution_type'    => 'manual',
            'evidence_record_id' => $existingRecord->id,
            'source_url'         => $url,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(200);
        $response->assertJsonPath('auto_resolved', 0);

        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertSame('manual', $item->resolution_type); // unchanged
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Refresh does nothing when no fresh proof exists
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_leaves_pending_when_no_fresh_proof(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'No Proof Plate',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => 'https://example.com/product/no-proof',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(200);
        $response->assertJsonPath('auto_resolved', 0);

        $item->refresh();
        $this->assertSame(EvidenceItemStatus::PENDING, $item->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Refresh updates run counters correctly
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_updates_run_counters(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url1 = 'https://example.com/product/plate-counter-1';
        $url2 = 'https://example.com/product/edge-counter-2';

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 2,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Counter Plate',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url1,
        ]);

        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::EDGE,
            'label'           => 'Counter Edge',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url2,
        ]);

        // Fresh proof only for plate
        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url1,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now(),
            'created_by'          => $user->id,
        ]);
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/test/counter-proof.jpg',
            'original_filename'  => 'counter-proof.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'counter-proof'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(200);
        $response->assertJsonPath('auto_resolved', 1);

        $run->refresh();
        $this->assertSame(1, $run->completed_items);
        $this->assertSame(2, $run->total_items);
        // Still IN_PROGRESS because edge item is still PENDING
        $this->assertSame(EvidenceRunStatus::IN_PROGRESS, $run->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. Refresh rejects finalized run
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_rejects_finalized_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::FINALIZED,
            'total_items'     => 0,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Stale proof does not auto-resolve on refresh
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_does_not_resolve_from_stale_proof(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-stale-refresh';

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Stale Refresh Plate',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        // Create a stale record (10 days old)
        $staleRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now()->subDays(10),
            'created_by'          => $user->id,
        ]);
        $staleRecord->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $staleRecord->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/test/stale-refresh.jpg',
            'original_filename'  => 'stale-refresh.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'stale-refresh'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(200);
        $response->assertJsonPath('auto_resolved', 0);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Run transitions to READY when all items become terminal
    // ──────────────────────────────────────────────────────────────

    public function test_refresh_transitions_run_to_ready_when_all_resolved(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-all-terminal';

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Only Item',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now(),
            'created_by'          => $user->id,
        ]);
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/test/all-terminal.jpg',
            'original_filename'  => 'all-terminal.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'all-terminal'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/refresh");

        $response->assertStatus(200);
        $response->assertJsonPath('auto_resolved', 1);

        $run->refresh();
        $this->assertSame(EvidenceRunStatus::READY, $run->status);
        $this->assertSame(1, $run->completed_items);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'     => $user->id,
            'number'      => 'PRJ-REFRESH-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address'     => 'Test Address',
        ]);
    }
}
