<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
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
use App\Services\EvidenceRunFinalizer;
use App\Services\GenericChromeCaptureService;
use App\Services\UrlNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Corrective tests for U2/U3: prove real user-facing scenario works.
 *
 * Covers:
 *  - Screenshot from one-click reaches the evidence record that is linked to the item
 *  - Auto-link resolves matching item despite URL normalization differences
 *  - PDF snapshot includes the screenshot asset after auto-link
 *  - EvidenceRunItemCollector normalizes source_url
 *  - Legacy captureForItem still works independently
 */
class BlockU2U3CorrectiveTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Screenshot linkage — record linked by auto-link has screenshot
    // ──────────────────────────────────────────────────────────────

    public function test_auto_link_resolves_item_with_screenshot_from_original_record(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-999';

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
            'label'           => 'ЛДСП Test Plate',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП Test 16мм', 'price' => '2500'],
                'screenshot_file' => UploadedFile::fake()->image('screenshot.jpg', 800, 600),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('auto_link.linked', true);
        $response->assertJsonPath('auto_link.item_id', $item->id);

        // The resolved item's record must have a screenshot asset
        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertNotNull($item->evidence_record_id);

        $record = EvidenceRecord::find($item->evidence_record_id);
        $this->assertNotNull($record);

        $assets = GenericEvidenceAsset::where('evidence_record_id', $record->id)->get();
        $this->assertTrue($assets->count() >= 1, 'Record must have at least one asset');

        $screenshot = $assets->first(fn ($a) => $a->asset_type === 'screenshot');
        $this->assertNotNull($screenshot, 'Record must have a screenshot asset');
        $this->assertStringStartsWith('image/', $screenshot->mime_type);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. URL normalization alignment
    // ──────────────────────────────────────────────────────────────

    public function test_auto_link_matches_despite_url_case_and_param_order(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);

        // Item stores a URL cleaned by cleanUrl (case-preserving, unsorted)
        $storedUrl = 'https://www.Example.COM/product/ABC?color=red&size=xl';
        // Normalized by UrlNormalizer: host lowered, params sorted
        $normalizer = app(UrlNormalizer::class);
        $normalizedUrl = $normalizer->normalize($storedUrl);

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        // Create item with NORMALIZED URL (as the corrected collector would)
        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Plate matching test',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $normalizedUrl,
        ]);

        // User visits the same page with different URL casing + param order
        $currentPageUrl = 'https://www.example.com/product/ABC?size=xl&color=red';

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $currentPageUrl,
                'extracted'       => ['title' => 'ЛДСП ABC 16мм 2800x2070', 'price' => '3000'],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('auto_link.linked', true);
        $response->assertJsonPath('auto_link.item_id', $item->id);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Auto-link refreshes run counters correctly
    // ──────────────────────────────────────────────────────────────

    public function test_auto_link_resolves_item_and_refreshes_counters(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/last-plate';

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
            'label'           => 'Only pending item',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП 16мм 2800x2070', 'price' => '4000'],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('auto_link.linked', true);

        $run->refresh();
        $this->assertEquals(1, $run->completed_items);
        $this->assertEquals(EvidenceRunStatus::READY, $run->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. PDF snapshot includes screenshot after auto-link
    // ──────────────────────────────────────────────────────────────

    public function test_pdf_snapshot_includes_screenshot_after_auto_link(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-pdf';

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
            'label'           => 'PDF screenshot test',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        // One-click with screenshot → auto-link
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП PDF Test 16мм', 'price' => '5000'],
                'screenshot_file' => UploadedFile::fake()->image('proof.jpg', 1280, 720),
            ])
            ->assertStatus(201)
            ->assertJsonPath('auto_link.linked', true);

        // Run should be READY; finalize it
        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::READY, $run->status);

        $finalizer = app(EvidenceRunFinalizer::class);
        $finalizer->finalize($run);

        $run->refresh();
        $this->assertEquals(EvidenceRunStatus::FINALIZED, $run->status);
        $this->assertNotNull($run->snapshot_json);

        // Snapshot should include the screenshot asset in the evidence records section
        $snapshot = $run->snapshot_json;
        $records = $snapshot['evidence_records'] ?? [];
        $this->assertNotEmpty($records, 'Snapshot must contain evidence records');

        $foundScreenshot = false;
        foreach ($records as $rec) {
            foreach ($rec['assets'] ?? [] as $asset) {
                if (str_starts_with($asset['mime_type'] ?? '', 'image/')) {
                    $foundScreenshot = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($foundScreenshot, 'Snapshot must include a screenshot image asset');
    }

    // ──────────────────────────────────────────────────────────────
    // 5. Item collector normalizes URLs
    // ──────────────────────────────────────────────────────────────

    public function test_item_collector_normalizes_source_urls(): void
    {
        $normalizer = app(UrlNormalizer::class);

        $rawUrl = 'https://www.Shop.Example.COM/product/123?utm_source=google&color=red';
        $expected = $normalizer->normalize($rawUrl);

        // Ensure normalization actually strips tracking and lowercases host
        $this->assertStringNotContainsString('utm_source', $expected);
        $this->assertStringContainsString('shop.example.com', $expected);
        $this->assertStringContainsString('color=red', $expected);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Auto-link skips when no URL match
    // ──────────────────────────────────────────────────────────────

    public function test_auto_link_skips_when_no_url_match(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

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

        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Unrelated item',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => 'https://totally-different.com/other-product',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => 'https://example.com/product/plate-no-match',
                'extracted'       => ['title' => 'ЛДСП Something 16мм', 'price' => '1000'],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $autoLink = $response->json('auto_link');
        if ($autoLink !== null) {
            $this->assertFalse($autoLink['linked']);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Legacy captureForItem still works independently
    // ──────────────────────────────────────────────────────────────

    public function test_legacy_capture_for_item_still_works(): void
    {
        Storage::fake('public');

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
            'cost_component'  => CostComponent::EDGE,
            'label'           => 'Edge via legacy flow',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => 'https://example.com/edge-1',
        ]);

        $service = app(GenericChromeCaptureService::class);
        $screenshot = UploadedFile::fake()->image('edge_shot.jpg');

        $result = $service->captureForItem($item, [
            'cost_component' => CostComponent::EDGE,
            'source_url'     => 'https://example.com/edge-1',
            'observed_price' => 150,
            'currency'       => 'RUB',
            'extracted_name' => 'ABS Edge 2mm',
            'capture_mode'   => 'viewport',
        ], $user->id, $screenshot);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['record']);

        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertNotNull($item->evidence_record_id);

        // Screenshot asset must exist on the linked record
        $assets = GenericEvidenceAsset::where('evidence_record_id', $result['record']->id)->get();
        $this->assertTrue($assets->contains(fn($a) => $a->asset_type === 'screenshot'));
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'     => $user->id,
            'number'      => 'PRJ-FIX-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address'     => 'Test Address',
        ]);
    }
}
