<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\ResolutionType;
use App\Evidence\SourceType;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\Project;
use App\Models\User;
use App\Services\GenericChromeCaptureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockG6ChromeHandoffTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. capture_mode 'viewport' is persisted in metadata_json
    // ──────────────────────────────────────────────────────────────

    public function test_viewport_capture_mode_persisted_in_metadata(): void
    {
        $user = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $result = $service->captureObservation([
            'cost_component' => CostComponent::PLATE,
            'source_url'     => 'https://store.example.com/plate',
            'observed_price' => 1000.00,
            'currency'       => 'RUB',
            'capture_mode'   => 'viewport',
        ], $user->id);

        $record = $result['record'];
        $this->assertNotNull($record->metadata_json);
        $this->assertEquals('viewport', $record->metadata_json['capture_mode']);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Capture without screenshot succeeds (graceful degradation)
    // ──────────────────────────────────────────────────────────────

    public function test_capture_observation_succeeds_without_screenshot(): void
    {
        $user = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $result = $service->captureObservation([
            'cost_component' => CostComponent::EDGE,
            'source_url'     => 'https://store.example.com/edge',
            'observed_price' => 250.00,
            'currency'       => 'RUB',
            'capture_mode'   => 'viewport',
        ], $user->id);

        $this->assertFalse($result['duplicate']);
        $this->assertNull($result['asset']);
        $this->assertInstanceOf(EvidenceRecord::class, $result['record']);
        $this->assertEquals('250.00', $result['record']->observed_price);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. captureForItem persists viewport capture_mode
    // ──────────────────────────────────────────────────────────────

    public function test_capture_for_item_persists_viewport_capture_mode(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $service = app(GenericChromeCaptureService::class);
        $result = $service->captureForItem($item, [
            'source_url'     => 'https://example.com/plate',
            'observed_price' => 1200.00,
            'currency'       => 'RUB',
            'capture_mode'   => 'viewport',
        ], $user->id);

        $this->assertTrue($result['success']);

        $record = $result['record'];
        $this->assertNotNull($record->metadata_json);
        $this->assertEquals('viewport', $record->metadata_json['capture_mode']);

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. API – captureGenericItem with capture_mode and no screenshot
    // ──────────────────────────────────────────────────────────────

    public function test_api_capture_generic_item_with_viewport_mode_without_screenshot(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::FITTING, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($user)
            ->postJson("/api/chrome/generic-items/{$item->id}/capture", [
                'source_url'     => 'https://fittings.example.com/hinge',
                'observed_price' => 450.00,
                'currency'       => 'RUB',
                'capture_mode'   => 'viewport',
            ]);

        $response->assertCreated()
            ->assertJson([
                'success'   => true,
                'duplicate' => false,
            ]);

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertEquals('450.00', $item->effective_value);

        $record = EvidenceRecord::find($response->json('data.record_id'));
        $this->assertNotNull($record);
        $this->assertEquals('viewport', $record->metadata_json['capture_mode']);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. API – captureGenericItem with screenshot persists asset
    // ──────────────────────────────────────────────────────────────

    public function test_api_capture_generic_item_with_screenshot_and_viewport_mode(): void
    {
        Storage::fake('public');
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $file = UploadedFile::fake()->image('screenshot.jpg', 800, 600);

        $response = $this->actingAs($user)
            ->post("/api/chrome/generic-items/{$item->id}/capture", [
                'source_url'      => 'https://store.example.com/plate',
                'observed_price'  => 2000.00,
                'currency'        => 'RUB',
                'capture_mode'    => 'viewport',
                'screenshot_file' => $file,
            ]);

        $response->assertCreated()
            ->assertJson([
                'success'   => true,
                'duplicate' => false,
            ]);

        $this->assertNotNull($response->json('data.asset_id'));

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. API – feature gate returns 403 when disabled
    // ──────────────────────────────────────────────────────────────

    public function test_api_capture_returns_404_when_feature_disabled(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($user)
            ->postJson("/api/chrome/generic-items/{$item->id}/capture", [
                'source_url'     => 'https://example.com/plate',
                'observed_price' => 1000.00,
                'currency'       => 'RUB',
                'capture_mode'   => 'viewport',
            ]);

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────
    // 7. API – duplicate detection on second capture for same item
    // ──────────────────────────────────────────────────────────────

    public function test_api_capture_duplicate_detection_on_observation(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();

        $payload = [
            'cost_component' => CostComponent::PLATE,
            'source_url'     => 'https://example.com/plate-dup',
            'observed_price' => 999.00,
            'currency'       => 'RUB',
            'capture_mode'   => 'viewport',
        ];

        $first = $this->actingAs($user)
            ->postJson('/api/chrome/capture-observation', $payload);

        $first->assertCreated()->assertJson(['duplicate' => false]);

        $second = $this->actingAs($user)
            ->postJson('/api/chrome/capture-observation', $payload);

        $second->assertOk()->assertJson(['duplicate' => true]);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'             => $user->id,
            'number'              => 'PRJ-G6-' . Str::random(4),
            'expert_name'         => 'G6 Test Expert',
            'address'             => 'Test Address',
            'waste_coefficient'   => 1.0,
            'repair_coefficient'  => 1.0,
        ]);
    }

    private function makeRun(Project $project, User $user): EstimateEvidenceRun
    {
        return EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::PENDING,
            'total_items'     => 0,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);
    }

    private function makeItem(
        EstimateEvidenceRun $run,
        string $costComponent,
        string $status,
    ): EstimateEvidenceItem {
        return EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => $costComponent,
            'label'           => 'G6 Test ' . $costComponent,
            'status'          => $status,
            'subject_type'    => 'test',
            'subject_id'      => 1,
        ]);
    }
}
