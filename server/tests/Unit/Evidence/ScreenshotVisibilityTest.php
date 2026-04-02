<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\User;
use App\Models\UserMaterialLibrary;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Screenshot Visibility + Evidence Projection Alignment tests.
 *
 * Covers:
 *  - One-click bridge: observation gets screenshot_path + evidence_record_id
 *  - Material detail API returns latest_screenshot
 *  - Trust breakdown reflects generic screenshot
 *  - Screenshot URL is a valid storage path
 *  - Legacy observation screenshot still works
 *  - No screenshot when evidence disabled
 */
class ScreenshotVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Bridge: one-click sets screenshot_path on observation
    // ──────────────────────────────────────────────────────────────

    public function test_one_click_bridges_screenshot_to_observation(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-bridge-test';

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП Bridge 16мм 2800x2070', 'price' => '2500'],
                'screenshot_file' => UploadedFile::fake()->image('screenshot.jpg', 800, 600),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'created');

        // Get the observation from the response
        $observationId = $response->json('observation.id');
        $this->assertNotNull($observationId);

        $observation = MaterialPriceHistory::find($observationId);
        $this->assertNotNull($observation);
        $this->assertNotNull($observation->screenshot_path, 'Observation must have screenshot_path bridged from evidence asset');
        $this->assertNotNull($observation->evidence_record_id, 'Observation must reference evidence record');

        // Verify screenshot_path points to a real file
        Storage::disk('public')->assertExists($observation->screenshot_path);
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Bridge: observation gets evidence_record_id
    // ──────────────────────────────────────────────────────────────

    public function test_one_click_bridges_evidence_record_to_observation(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-record-id-test';

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП RecordId Test 16мм 2800x2070', 'price' => '3000'],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);

        $observationId = $response->json('observation.id');
        $observation = MaterialPriceHistory::find($observationId);
        $recordId = $response->json('evidence.record_id');

        $this->assertNotNull($observation->evidence_record_id);
        $this->assertEquals($recordId, $observation->evidence_record_id);

        // The evidence record should have an asset
        $record = EvidenceRecord::find($observation->evidence_record_id);
        $this->assertNotNull($record);
        $this->assertTrue($record->assets()->where('asset_type', 'screenshot')->exists());
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Material detail API returns latest_screenshot
    // ──────────────────────────────────────────────────────────────

    public function test_material_detail_includes_latest_screenshot(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-detail-screenshot';

        // One-click capture
        $captureResp = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП Detail Screenshot 16мм 2800x2070', 'price' => '4200'],
                'screenshot_file' => UploadedFile::fake()->image('proof.jpg', 1280, 720),
            ]);

        $captureResp->assertStatus(201);
        $materialId = $captureResp->json('material.id');
        $this->assertNotNull($materialId);

        // Now fetch material detail
        $detailResp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$materialId}");

        $detailResp->assertStatus(200);
        $detailResp->assertJsonStructure([
            'latest_screenshot' => ['url', 'path', 'is_image', 'source', 'captured_at', 'exists'],
        ]);

        $screenshot = $detailResp->json('latest_screenshot');
        $this->assertTrue($screenshot['is_image']);
        $this->assertEquals('chrome_ext', $screenshot['source']);
        $this->assertNotEmpty($screenshot['url']);
        // exists may be false in faked storage as URL generation differs; path should be non-empty
        $this->assertNotEmpty($screenshot['path']);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Trust breakdown reflects generic screenshot presence
    // ──────────────────────────────────────────────────────────────

    public function test_trust_breakdown_reflects_generic_screenshot(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-trust-screenshot';

        // One-click capture with screenshot
        $captureResp = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП Trust Screenshot 16мм 2800x2070', 'price' => '3500'],
                'screenshot_file' => UploadedFile::fake()->image('trust.jpg'),
            ]);

        $captureResp->assertStatus(201);
        $materialId = $captureResp->json('material.id');

        // Fetch material detail
        $detailResp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$materialId}");

        $detailResp->assertStatus(200);

        $breakdown = $detailResp->json('trust_breakdown');
        $screenshotItem = collect($breakdown)->first(fn($item) =>
            str_contains($item['label'], 'Скриншот')
        );

        $this->assertNotNull($screenshotItem, 'Trust breakdown must contain screenshot item');
        $this->assertTrue($screenshotItem['met'], 'Screenshot trust item must be met after one-click with screenshot');
        $this->assertEquals(10, $screenshotItem['points']);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. No screenshot when evidence feature disabled
    // ──────────────────────────────────────────────────────────────

    public function test_no_screenshot_when_evidence_disabled(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-no-evidence';

        $captureResp = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => ['title' => 'ЛДСП NoEvidence 16мм 2800x2070', 'price' => '2000'],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $captureResp->assertStatus(201);
        $materialId = $captureResp->json('material.id');

        $detailResp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$materialId}");

        $detailResp->assertStatus(200);
        $this->assertNull($detailResp->json('latest_screenshot'));

        // Trust breakdown screenshot item should be not met
        $breakdown = $detailResp->json('trust_breakdown');
        $screenshotItem = collect($breakdown)->first(fn($item) =>
            str_contains($item['label'], 'Скриншот')
        );
        $this->assertNotNull($screenshotItem);
        $this->assertFalse($screenshotItem['met']);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Legacy observation screenshot still works
    // ──────────────────────────────────────────────────────────────

    public function test_legacy_observation_screenshot_still_works(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $material = Material::create([
            'user_id'          => $user->id,
            'name'             => 'ЛДСП Legacy 16мм',
            'article'          => 'LEG-001',
            'type'             => 'plate',
            'unit'             => 'м²',
            'price_per_unit'   => 1500,
            'source_url'       => 'https://example.com/legacy',
            'data_origin'      => 'manual',
            'visibility'       => 'private',
            'trust_level'      => 'unverified',
            'trust_score'      => 0,
        ]);

        // Create a legacy screenshot file
        $screenshotPath = 'screenshots/legacy/test.jpg';
        Storage::disk('public')->put($screenshotPath, 'fake-image-data');

        // Create observation with screenshot_path set directly (legacy path)
        MaterialPriceHistory::create([
            'material_id'     => $material->id,
            'version'         => 1,
            'valid_from'      => now()->toDateString(),
            'price_per_unit'  => 1500,
            'source_url'      => 'https://example.com/legacy',
            'observed_at'     => now(),
            'source_type'     => 'manual',
            'is_verified'     => false,
            'currency'        => 'RUB',
            'screenshot_path' => $screenshotPath,
        ]);

        $detailResp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}");

        $detailResp->assertStatus(200);

        $screenshot = $detailResp->json('latest_screenshot');
        $this->assertNotNull($screenshot);
        $this->assertTrue($screenshot['is_image']);
        $this->assertTrue($screenshot['exists']);
        $this->assertEquals('manual', $screenshot['source']);
        $this->assertStringContainsString($screenshotPath, $screenshot['url']);

        // Trust breakdown should also reflect screenshot
        $breakdown = $detailResp->json('trust_breakdown');
        $screenshotItem = collect($breakdown)->first(fn($item) =>
            str_contains($item['label'], 'Скриншот')
        );
        $this->assertTrue($screenshotItem['met']);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Evidence record fallback resolves screenshot
    // ──────────────────────────────────────────────────────────────

    public function test_evidence_record_fallback_resolves_screenshot(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $material = Material::create([
            'user_id'          => $user->id,
            'name'             => 'ЛДСП Fallback 16мм',
            'article'          => 'FBK-001',
            'type'             => 'plate',
            'unit'             => 'м²',
            'price_per_unit'   => 2000,
            'source_url'       => 'https://example.com/fallback',
            'data_origin'      => 'chrome_ext',
            'visibility'       => 'private',
            'trust_level'      => 'unverified',
            'trust_score'      => 0,
        ]);

        // Create evidence record with asset
        $record = EvidenceRecord::create([
            'uuid'            => (string) Str::uuid(),
            'cost_component'  => CostComponent::PLATE,
            'source_type'     => 'chrome_ext',
            'capture_method'  => 'one_click',
            'source_url'      => 'https://example.com/fallback',
            'observed_price'  => 2000,
            'currency'        => 'RUB',
            'observed_at'     => now(),
            'created_by'      => $user->id,
        ]);

        $assetPath = 'screenshots/chrome/generic/2026/04/test-fallback.jpg';
        Storage::disk('public')->put($assetPath, 'fake-screenshot-data');

        GenericEvidenceAsset::create([
            'uuid'                => (string) Str::uuid(),
            'evidence_record_id'  => $record->id,
            'asset_type'          => 'screenshot',
            'file_path'           => $assetPath,
            'mime_type'           => 'image/jpeg',
            'sha256'              => hash('sha256', 'fake-screenshot-data'),
        ]);

        // Create observation with evidence_record_id but NO screenshot_path
        // This simulates the fallback scenario
        MaterialPriceHistory::create([
            'material_id'        => $material->id,
            'version'            => 1,
            'valid_from'         => now()->toDateString(),
            'price_per_unit'     => 2000,
            'source_url'         => 'https://example.com/fallback',
            'observed_at'        => now(),
            'source_type'        => 'chrome_ext',
            'is_verified'        => true,
            'currency'           => 'RUB',
            'evidence_record_id' => $record->id,
            // screenshot_path intentionally null — fallback test
        ]);

        $detailResp = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}");

        $detailResp->assertStatus(200);

        $screenshot = $detailResp->json('latest_screenshot');
        $this->assertNotNull($screenshot, 'Fallback via evidence_record_id must resolve screenshot');
        $this->assertTrue($screenshot['is_image']);
        $this->assertTrue($screenshot['exists']);
        $this->assertStringContainsString($assetPath, $screenshot['url']);

        // Trust breakdown should also detect screenshot via fallback
        $breakdown = $detailResp->json('trust_breakdown');
        $screenshotItem = collect($breakdown)->first(fn($item) =>
            str_contains($item['label'], 'Скриншот')
        );
        $this->assertTrue($screenshotItem['met']);
    }
}
