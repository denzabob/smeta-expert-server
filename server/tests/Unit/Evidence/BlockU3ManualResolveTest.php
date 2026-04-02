<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockU3ManualResolveTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Evidence Record Search
    // ──────────────────────────────────────────────────────────────

    public function test_search_records_returns_user_records(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Create records for our user
        EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => 'https://example.com/plate-1',
            'source_domain'       => 'example.com',
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'extracted_name'      => 'ЛДСП Egger H3303',
            'created_by'          => $user->id,
        ]);

        // Another user's record — should NOT appear
        EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => 'https://example.com/other-plate',
            'created_by'          => $other->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/evidence-records/search');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.extracted_name', 'ЛДСП Egger H3303');
        $response->assertJsonStructure([
            'data' => [['id', 'uuid', 'extracted_name', 'source_url', 'observed_price', 'has_screenshot']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_search_records_filters_by_text_query(): void
    {
        $user = User::factory()->create();

        EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'extracted_name' => 'ЛДСП Egger H3303',
            'source_url'     => 'https://shop.example.com/plate-h3303',
            'created_by'     => $user->id,
        ]);

        EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::EDGE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'extracted_name' => 'Кромка ABS 2мм',
            'source_url'     => 'https://edge.example.com/abs-2',
            'created_by'     => $user->id,
        ]);

        // Search by name
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/evidence-records/search?q=Egger');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.extracted_name', 'ЛДСП Egger H3303');
    }

    public function test_search_records_filters_by_cost_component(): void
    {
        $user = User::factory()->create();

        EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'extracted_name' => 'Plate record',
            'created_by'     => $user->id,
        ]);

        EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::EDGE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'extracted_name' => 'Edge record',
            'created_by'     => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/evidence-records/search?cost_component=edge');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.extracted_name', 'Edge record');
    }

    public function test_search_records_includes_has_screenshot_flag(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'extracted_name' => 'Record with screenshot',
            'created_by'     => $user->id,
        ]);

        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/test.jpg',
            'original_filename'  => 'test.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'test'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/evidence-records/search');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.has_screenshot', true);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Manual Resolve
    // ──────────────────────────────────────────────────────────────

    public function test_manual_resolve_creates_record_and_resolves_item(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 0,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'ЛДСП Egger H3303',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => 'https://example.com/plate-h3303',
        ]);

        $file = UploadedFile::fake()->image('proof.jpg', 800, 600);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/manual-resolve", [
                'file'           => $file,
                'observed_price' => 3500,
                'currency'       => 'RUB',
                'source_url'     => 'https://example.com/plate-h3303',
                'extracted_name' => 'ЛДСП H3303',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'resolved');
        $response->assertJsonPath('data.resolution_type', 'manual');
        $response->assertJsonPath('data.effective_value', '3500.00');

        // Verify record was created
        $item->refresh();
        $this->assertNotNull($item->evidence_record_id);

        $record = EvidenceRecord::find($item->evidence_record_id);
        $this->assertEquals(SourceType::MANUAL_INPUT, $record->source_type);
        $this->assertEquals(CaptureMethod::FILE_UPLOAD, $record->capture_method);
        $this->assertEquals(CostComponent::PLATE, $record->cost_component);

        // Verify asset was stored
        $this->assertEquals(1, GenericEvidenceAsset::where('evidence_record_id', $record->id)->count());
    }

    public function test_manual_resolve_transitions_run_to_ready(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 0,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Last pending item',
            'status'          => EvidenceItemStatus::PENDING,
        ]);

        $file = UploadedFile::fake()->image('proof.png');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/manual-resolve", [
                'file'           => $file,
                'observed_price' => 2000,
            ]);

        $response->assertStatus(201);

        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::READY, $run->status);
        $this->assertEquals(1, $run->completed_items);
    }

    public function test_manual_resolve_rejects_terminal_item(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 1,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Already resolved',
            'status'          => EvidenceItemStatus::RESOLVED,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/manual-resolve", [
                'file'           => UploadedFile::fake()->image('proof.jpg'),
                'observed_price' => 1000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_manual_resolve_rejects_finalized_run(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::FINALIZED,
            'total_items'  => 1,
            'completed_items' => 1,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Item in finalized run',
            'status'          => EvidenceItemStatus::RESOLVED,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/manual-resolve", [
                'file'           => UploadedFile::fake()->image('proof.jpg'),
                'observed_price' => 1000,
            ]);

        $response->assertStatus(422);
    }

    public function test_manual_resolve_requires_file_and_price(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 0,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Pending item',
            'status'          => EvidenceItemStatus::PENDING,
        ]);

        // No file
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/manual-resolve", [
                'observed_price' => 1000,
            ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Picker resolve (existing endpoint, but via record from search)
    // ──────────────────────────────────────────────────────────────

    public function test_resolve_item_with_record_from_search(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'observed_price' => 4500,
            'currency'       => 'RUB',
            'extracted_name' => 'Plate from search',
            'created_by'     => $user->id,
        ]);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 0,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Item to resolve via picker',
            'status'          => EvidenceItemStatus::PENDING,
        ]);

        // Simulate picker: search → select → resolve
        $searchResponse = $this->actingAs($user, 'sanctum')
            ->getJson('/api/evidence-records/search?cost_component=plate');

        $searchResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($searchResponse->json('data')));

        $pickedId = $searchResponse->json('data.0.id');

        $resolveResponse = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve", [
                'evidence_record_id' => $pickedId,
            ]);

        $resolveResponse->assertStatus(200);
        $resolveResponse->assertJsonPath('success', true);
        $resolveResponse->assertJsonPath('data.status', 'resolved');
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Legacy endpoints unaffected
    // ──────────────────────────────────────────────────────────────

    public function test_legacy_resolve_with_raw_id_still_works(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => CostComponent::EDGE,
            'source_type'    => SourceType::CHROME_CAPTURE,
            'capture_method' => CaptureMethod::CHROME_EXTENSION,
            'observed_price' => 200,
            'currency'       => 'RUB',
            'created_by'     => $user->id,
        ]);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 0,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::EDGE,
            'label'           => 'Edge item',
            'status'          => EvidenceItemStatus::PENDING,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/resolve", [
                'evidence_record_id' => $record->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'resolved');
    }

    // ──────────────────────────────────────────────────────────────
    // 5. Error handling
    // ──────────────────────────────────────────────────────────────

    public function test_search_records_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/evidence-records/search');
        $response->assertStatus(401);
    }

    public function test_manual_resolve_invalid_price_returns_422(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => EvidenceRunStatus::IN_PROGRESS,
            'total_items'  => 1,
            'completed_items' => 0,
            'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Item',
            'status'          => EvidenceItemStatus::PENDING,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/items/{$item->id}/manual-resolve", [
                'file'           => UploadedFile::fake()->image('proof.jpg'),
                'observed_price' => -100,
            ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'     => $user->id,
            'number'      => 'PRJ-U3-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address'     => 'Test Address',
        ]);
    }
}
