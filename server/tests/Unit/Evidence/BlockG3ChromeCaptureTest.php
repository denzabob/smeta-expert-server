<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\ResolutionType;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Models\User;
use App\Services\GenericChromeCaptureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockG3ChromeCaptureTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Service – captureObservation creates EvidenceRecord
    // ──────────────────────────────────────────────────────────────

    public function test_capture_observation_creates_evidence_record(): void
    {
        $user = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $result = $service->captureObservation([
            'cost_component'  => CostComponent::PLATE,
            'source_url'      => 'https://example.com/product?id=42&utm_source=test',
            'observed_price'  => 1500.50,
            'currency'        => 'rub',
            'extracted_name'  => 'ЛДСП Белый 16мм',
            'extracted_article' => 'ART-001',
            'capture_mode'    => 'template',
        ], $user->id);

        $this->assertFalse($result['duplicate']);
        $this->assertNull($result['asset']);

        $record = $result['record'];
        $this->assertInstanceOf(EvidenceRecord::class, $record);
        $this->assertEquals(CostComponent::PLATE, $record->cost_component);
        $this->assertEquals(SourceType::CHROME_CAPTURE, $record->source_type);
        $this->assertEquals(CaptureMethod::CHROME_EXTENSION, $record->capture_method);
        $this->assertEquals(VerificationStatus::PENDING, $record->verification_status);
        // URL should be normalized (utm_source stripped)
        $this->assertEquals('https://example.com/product?id=42', $record->source_url);
        $this->assertEquals('example.com', $record->source_domain);
        $this->assertEquals('1500.50', $record->observed_price);
        $this->assertEquals('RUB', $record->currency);
        $this->assertEquals('ЛДСП Белый 16мм', $record->extracted_name);
        $this->assertEquals('ART-001', $record->extracted_article);
        $this->assertEquals(60, $record->trust_score);
        $this->assertEquals($user->id, $record->created_by);
        $this->assertNotNull($record->metadata_json);
        $this->assertEquals('template', $record->metadata_json['capture_mode']);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Service – captureObservation with screenshot
    // ──────────────────────────────────────────────────────────────

    public function test_capture_observation_stores_screenshot_asset(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $file = UploadedFile::fake()->image('screenshot.jpg', 800, 600);

        $result = $service->captureObservation([
            'cost_component' => CostComponent::EDGE,
            'source_url'     => 'https://store.example.com/edge',
            'observed_price' => 200.00,
            'currency'       => 'RUB',
        ], $user->id, $file);

        $this->assertFalse($result['duplicate']);
        $this->assertNotNull($result['asset']);

        $asset = $result['asset'];
        $this->assertInstanceOf(GenericEvidenceAsset::class, $asset);
        $this->assertEquals($result['record']->id, $asset->evidence_record_id);
        $this->assertEquals('screenshot', $asset->asset_type);
        $this->assertNotNull($asset->sha256);
        $this->assertNotNull($asset->file_path);
        Storage::disk('public')->assertExists($asset->file_path);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Service – dedup within 60 seconds
    // ──────────────────────────────────────────────────────────────

    public function test_capture_observation_deduplicates_within_60_seconds(): void
    {
        $user = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $payload = [
            'cost_component' => CostComponent::FITTING,
            'source_url'     => 'https://fittings.example.com/hinge',
            'observed_price' => 300.00,
            'currency'       => 'RUB',
        ];

        $first = $service->captureObservation($payload, $user->id);
        $this->assertFalse($first['duplicate']);

        $second = $service->captureObservation($payload, $user->id);
        $this->assertTrue($second['duplicate']);
        $this->assertEquals($first['record']->id, $second['record']->id);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Service – captureForItem resolves an evidence item
    // ──────────────────────────────────────────────────────────────

    public function test_capture_for_item_resolves_evidence_item(): void
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
        ], $user->id);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['duplicate']);

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertEquals(ResolutionType::CHROME, $item->resolution_type);
        $this->assertEquals($result['record']->id, $item->evidence_record_id);
        $this->assertEquals('1200.00', $item->effective_value);
        $this->assertEquals('RUB', $item->currency);

        // Verify EvidenceLink was created
        $link = EvidenceLink::where('evidence_record_id', $result['record']->id)
            ->where('linkable_type', EstimateEvidenceItem::class)
            ->where('linkable_id', $item->id)
            ->first();
        $this->assertNotNull($link);
        $this->assertEquals('captured_for', $link->relation_type);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. Service – captureForItem transitions run to READY
    // ──────────────────────────────────────────────────────────────

    public function test_capture_for_item_transitions_run_to_ready_when_all_terminal(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $service = app(GenericChromeCaptureService::class);
        $service->captureForItem($item, [
            'source_url'     => 'https://example.com/plate',
            'observed_price' => 1500.00,
            'currency'       => 'RUB',
        ], $user->id);

        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::READY, $run->status);
        $this->assertEquals(1, $run->completed_items);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Service – captureForItem rejects terminal item
    // ──────────────────────────────────────────────────────────────

    public function test_capture_for_item_rejects_terminal_item(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::RESOLVED);

        $service = app(GenericChromeCaptureService::class);
        $result = $service->captureForItem($item, [
            'source_url'     => 'https://example.com/plate',
            'observed_price' => 1500.00,
            'currency'       => 'RUB',
        ], $user->id);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('terminal', $result['error']);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. API – generic items listing
    // ──────────────────────────────────────────────────────────────

    public function test_list_generic_items_returns_open_items(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 2]);

        $pending = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);
        $resolved = $this->makeItem($run, CostComponent::EDGE, EvidenceItemStatus::RESOLVED);

        $response = $this->actingAs($user)->getJson('/api/chrome/generic-items');

        $response->assertStatus(200)
            ->assertJsonStructure(['items', 'total']);

        $ids = collect($response->json('items'))->pluck('id')->all();
        $this->assertContains($pending->id, $ids);
        $this->assertNotContains($resolved->id, $ids);
    }

    public function test_list_generic_items_excludes_other_users(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($stranger)->getJson('/api/chrome/generic-items');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('total'));
    }

    // ──────────────────────────────────────────────────────────────
    // 8. API – capture observation endpoint
    // ──────────────────────────────────────────────────────────────

    public function test_capture_observation_endpoint_creates_record(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/chrome/capture-observation', [
            'cost_component' => CostComponent::FACADE,
            'source_url'     => 'https://facade.example.com/item',
            'observed_price' => 5000.00,
            'currency'       => 'RUB',
            'extracted_name' => 'Фасад МДФ',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonStructure(['data' => ['record_id', 'record_uuid']]);

        $recordId = $response->json('data.record_id');
        $record = EvidenceRecord::find($recordId);
        $this->assertNotNull($record);
        $this->assertEquals(CostComponent::FACADE, $record->cost_component);
        $this->assertEquals(SourceType::CHROME_CAPTURE, $record->source_type);
    }

    // ──────────────────────────────────────────────────────────────
    // 9. API – capture generic item endpoint
    // ──────────────────────────────────────────────────────────────

    public function test_capture_generic_item_endpoint_resolves_item(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::FITTING, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($user)->postJson(
            "/api/chrome/generic-items/{$item->id}/capture",
            [
                'source_url'     => 'https://fittings.example.com/hinge',
                'observed_price' => 250.00,
                'currency'       => 'RUB',
            ]
        );

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('item_id', $item->id)
            ->assertJsonPath('data.item_status', EvidenceItemStatus::RESOLVED);

        $item->refresh();
        $this->assertEquals(EvidenceItemStatus::RESOLVED, $item->status);
    }

    public function test_capture_generic_item_denies_other_user(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->makeProject($user);
        $run = $this->makeRun($project, $user);
        $run->update(['status' => EvidenceRunStatus::IN_PROGRESS, 'total_items' => 1]);
        $item = $this->makeItem($run, CostComponent::PLATE, EvidenceItemStatus::PENDING);

        $response = $this->actingAs($stranger)->postJson(
            "/api/chrome/generic-items/{$item->id}/capture",
            [
                'source_url'     => 'https://example.com/plate',
                'observed_price' => 1000.00,
                'currency'       => 'RUB',
            ]
        );

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────
    // 10. Screenshot sha256 dedup within same record
    // ──────────────────────────────────────────────────────────────

    public function test_screenshot_dedup_by_sha256_within_record(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'created_by'          => $user->id,
        ]);

        $file1 = UploadedFile::fake()->create('screenshot1.jpg', 100, 'image/jpeg');
        $asset1 = $service->storeScreenshot($record, $file1);

        // Same content file → should return same asset
        $file2 = UploadedFile::fake()->create('screenshot2.jpg', 100, 'image/jpeg');

        // Force same content for dedup test
        file_put_contents($file2->getRealPath(), file_get_contents($file1->getRealPath()));

        $asset2 = $service->storeScreenshot($record, $file2);
        $this->assertEquals($asset1->id, $asset2->id);
    }

    // ──────────────────────────────────────────────────────────────
    // 11. Metadata builder maps known keys
    // ──────────────────────────────────────────────────────────────

    public function test_metadata_builder_extracts_known_keys(): void
    {
        $service = app(GenericChromeCaptureService::class);

        $result = $service->buildMetadataJson([
            'cost_component' => CostComponent::PLATE,
            'source_url'     => 'https://example.com',
            'capture_mode'   => 'manual',
            'template_id'    => 42,
            'unknown_field'  => 'ignored',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('manual', $result['capture_mode']);
        $this->assertEquals(42, $result['template_id']);
        $this->assertArrayNotHasKey('unknown_field', $result);
        $this->assertArrayNotHasKey('cost_component', $result);
    }

    public function test_metadata_builder_returns_null_when_no_keys(): void
    {
        $service = app(GenericChromeCaptureService::class);

        $result = $service->buildMetadataJson([
            'cost_component' => CostComponent::PLATE,
            'source_url'     => 'https://example.com',
        ]);

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────────
    // 12. Legacy endpoints remain unaffected
    // ──────────────────────────────────────────────────────────────

    public function test_legacy_extract_endpoint_still_works(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $user = User::factory()->create();

        // Just confirm the route exists and returns expected validation error
        $response = $this->actingAs($user)->postJson('/api/chrome/extract', []);

        // Validation error (422) means route is reachable, not 404
        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────
    // 13. Dedupe – standalone dedup does not cross user boundaries
    // ──────────────────────────────────────────────────────────────

    public function test_standalone_dedupe_does_not_cross_user_boundary(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $service = app(GenericChromeCaptureService::class);

        $payload = [
            'cost_component' => CostComponent::PLATE,
            'source_url'     => 'https://store.example.com/sheet',
            'observed_price' => 1200.00,
            'currency'       => 'RUB',
        ];

        // User A creates a record
        $resultA = $service->captureObservation($payload, $userA->id);
        $this->assertFalse($resultA['duplicate']);

        // User B same URL+component within 60s → must NOT dedup into A's record
        $resultB = $service->captureObservation($payload, $userB->id);
        $this->assertFalse($resultB['duplicate']);
        $this->assertNotEquals($resultA['record']->id, $resultB['record']->id);
        $this->assertEquals($userB->id, $resultB['record']->created_by);

        // User A again → SHOULD dedup into their own record
        $resultA2 = $service->captureObservation($payload, $userA->id);
        $this->assertTrue($resultA2['duplicate']);
        $this->assertEquals($resultA['record']->id, $resultA2['record']->id);
    }

    // ──────────────────────────────────────────────────────────────
    // 14. Feature gate – endpoints return 404 when gate is off
    // ──────────────────────────────────────────────────────────────

    public function test_generic_endpoints_return_404_when_gate_off(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/chrome/generic-items')
            ->assertStatus(404);

        $this->actingAs($user)->postJson('/api/chrome/capture-observation', [
            'cost_component' => CostComponent::PLATE,
            'source_url'     => 'https://example.com/test',
        ])->assertStatus(404);

        $this->actingAs($user)->postJson('/api/chrome/generic-items/1/capture', [
            'source_url' => 'https://example.com/test',
        ])->assertStatus(404);

        // extract-with-evidence no longer 404s when gate is off:
        // material upsert always proceeds, evidence creation is skipped explicitly
        $this->actingAs($user, 'sanctum')->postJson('/api/chrome/extract-with-evidence', [
            'url' => 'https://example.com/test-gate-off',
            'extracted' => ['title' => 'Gate off test', 'price' => '100 ₽'],
        ])->assertStatus(201)
          ->assertJsonPath('evidence_status', 'skipped_feature_disabled');
    }

    // ──────────────────────────────────────────────────────────────
    // 15. Feature gate – EvidenceFeatures responds to config
    // ──────────────────────────────────────────────────────────────

    public function test_generic_chrome_feature_flag_responds_to_config(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);
        $this->assertFalse(EvidenceFeatures::genericChromeEnabled());

        config(['smeta.evidence.generic_chrome_enabled' => true]);
        $this->assertTrue(EvidenceFeatures::genericChromeEnabled());
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────


    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'             => $user->id,
            'number'              => 'PRJ-G3-' . Str::random(4),
            'expert_name'         => 'Test Expert',
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
            'label'           => 'Test ' . $costComponent,
            'status'          => $status,
            'subject_type'    => 'test',
            'subject_id'      => 1,
        ]);
    }
}
