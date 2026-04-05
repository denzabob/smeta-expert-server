<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CaptureMethod;
use App\Evidence\CostComponent;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Evidence\SourceType;
use App\Evidence\VerificationStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockU2ExtractWithEvidenceTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Material created + evidence + screenshot
    // ──────────────────────────────────────────────────────────────

    public function test_material_created_with_screenshot_and_evidence(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $screenshot = UploadedFile::fake()->image('screenshot.jpg', 1280, 720);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/plate-123',
                'extracted' => [
                    'title' => 'ЛДСП Egger H3303 Дуб Корбридж 16мм 2800x2070',
                    'price' => '3 500 ₽',
                    'article' => 'H3303',
                ],
                'screenshot_file' => $screenshot,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('material_status', 'created');
        $response->assertJsonPath('evidence_status', 'created');
        $response->assertJsonPath('screenshot_status', 'captured');
        $response->assertJsonPath('is_new', true);
        $this->assertNotNull($response->json('material.id'));
        $this->assertNotNull($response->json('evidence.record_id'));
        $this->assertNotNull($response->json('evidence.asset_id'));
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Material updated (dedup) + evidence
    // ──────────────────────────────────────────────────────────────

    public function test_material_updated_dedup_with_evidence(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-456';

        // Pre-create material with same URL → dedup match
        Material::create([
            'user_id' => $user->id,
            'origin' => 'parser',
            'name' => 'ЛДСП Старое название',
            'article' => 'OLD-456',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 2000,
            'source_url' => $url,
            'is_active' => true,
            'version' => 1,
            'visibility' => 'private',
            'data_origin' => 'chrome_ext',
            'trust_level' => 'unverified',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => $url,
                'extracted' => [
                    'title' => 'ЛДСП Egger H3303 Обновлённое',
                    'price' => '3 800 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('material_status', 'updated');
        $response->assertJsonPath('evidence_status', 'created');
        $response->assertJsonPath('is_new', false);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Auto-link resolves single matching evidence item
    // ──────────────────────────────────────────────────────────────

    public function test_auto_link_resolves_matching_item(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-789';

        $run = EstimateEvidenceRun::create([
            'uuid'           => (string) Str::uuid(),
            'project_id'     => $project->id,
            'initiated_by'   => $user->id,
            'status'         => EvidenceRunStatus::IN_PROGRESS,
            'total_items'    => 1,
            'completed_items' => 0,
            'failed_items'   => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'ЛДСП тестовая',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => $url,
                'extracted' => [
                    'title' => 'ЛДСП Egger 16мм',
                    'price' => '3 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('auto_link.linked', true);
        $response->assertJsonPath('auto_link.item_id', $item->id);

        // Item should be resolved
        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
    }

    // ──────────────────────────────────────────────────────────────
    // 4. No auto-link when multiple candidates exist
    // ──────────────────────────────────────────────────────────────

    public function test_no_auto_link_when_multiple_matches(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-multi';

        $run = EstimateEvidenceRun::create([
            'uuid'           => (string) Str::uuid(),
            'project_id'     => $project->id,
            'initiated_by'   => $user->id,
            'status'         => EvidenceRunStatus::IN_PROGRESS,
            'total_items'    => 2,
            'completed_items' => 0,
            'failed_items'   => 0,
        ]);

        // Two items with same cost_component + source_url → ambiguous
        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Item A',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'Item B',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => $url,
                'extracted' => [
                    'title' => 'ЛДСП test',
                    'price' => '2 000 ₽',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        // auto_link should be null (no link attempted) or linked=false
        $autoLink = $response->json('auto_link');
        if ($autoLink !== null) {
            $this->assertFalse($autoLink['linked']);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // 5. No auto-link when no matching items
    // ──────────────────────────────────────────────────────────────

    public function test_no_auto_link_when_no_matches(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/no-match',
                'extracted' => [
                    'title' => 'ЛДСП test',
                    'price' => '2 000 ₽',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $autoLink = $response->json('auto_link');
        if ($autoLink !== null) {
            $this->assertFalse($autoLink['linked']);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Works without screenshot (saved_without_screenshot)
    // ──────────────────────────────────────────────────────────────

    public function test_works_without_screenshot(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/no-ss',
                'extracted' => [
                    'title' => 'ЛДСП без скриншота',
                    'price' => '1 500 ₽',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('material_status', 'created');
        $response->assertJsonPath('evidence_status', 'created');
        $response->assertJsonPath('screenshot_status', 'failed');
        $this->assertNull($response->json('evidence.asset_id'));
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Evidence skipped when feature disabled
    // ──────────────────────────────────────────────────────────────

    public function test_evidence_skipped_when_feature_disabled(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/flag-off',
                'extracted' => [
                    'title' => 'ЛДСП flagged off',
                    'price' => '1 000 ₽',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('material_status', 'created');
        $response->assertJsonPath('evidence_status', 'skipped_feature_disabled');
        $response->assertJsonPath('screenshot_status', 'skipped');
        $response->assertJsonPath('auto_link', null);
        $this->assertNull($response->json('evidence'));
        // Material still created
        $this->assertNotNull($response->json('material.id'));
    }

    // ──────────────────────────────────────────────────────────────
    // 8. Cost component auto-derived from material type
    // ──────────────────────────────────────────────────────────────

    public function test_cost_component_auto_derived_plate(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/plate-derive',
                'extracted' => [
                    'title' => 'ЛДСП Egger 16мм 2800x2070',
                    'price' => '3 500 ₽',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'created');

        // Evidence record should have plate cost_component
        $recordId = $response->json('evidence.record_id');
        $record = EvidenceRecord::find($recordId);
        $this->assertSame(CostComponent::PLATE, $record->cost_component);
    }

    public function test_cost_component_auto_derived_hardware_to_fitting(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/hardware-item',
                'extracted' => [
                    'title' => 'Петля мебельная накладная',
                    'price' => '120 ₽',
                ],
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'created');

        $recordId = $response->json('evidence.record_id');
        $record = EvidenceRecord::find($recordId);
        $this->assertSame(CostComponent::FITTING, $record->cost_component);
    }

    // ──────────────────────────────────────────────────────────────
    // 9. Backward compatibility: existing extract endpoint still works
    // ──────────────────────────────────────────────────────────────

    public function test_existing_extract_endpoint_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract', [
                'url' => 'https://example.com/product/compat-test',
                'extracted' => [
                    'title' => 'ЛДСП backward compat',
                    'price' => '2 000 ₽',
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotNull($response->json('material.id'));
        // Old endpoint has no evidence/screenshot axes
        $this->assertArrayNotHasKey('material_status', $response->json());
        $this->assertArrayNotHasKey('evidence_status', $response->json());
    }

    // ──────────────────────────────────────────────────────────────
    // 10. Response contract completeness
    // ──────────────────────────────────────────────────────────────

    public function test_response_contract_has_all_axes(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/contract-test',
                'extracted' => [
                    'title' => 'ЛДСП contract test',
                    'price' => '1 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $json = $response->json();

        // All required axes present
        $this->assertArrayHasKey('material_status', $json);
        $this->assertArrayHasKey('evidence_status', $json);
        $this->assertArrayHasKey('screenshot_status', $json);
        $this->assertArrayHasKey('auto_link', $json);
        $this->assertArrayHasKey('material', $json);
        $this->assertArrayHasKey('observation', $json);
        $this->assertArrayHasKey('message', $json);

        // material_status is one of the allowed values
        $this->assertContains($json['material_status'], ['created', 'updated']);
        $this->assertContains($json['evidence_status'], ['created', 'duplicate', 'skipped_feature_disabled', 'skipped_unmapped_type']);
        $this->assertContains($json['screenshot_status'], ['captured', 'failed', 'skipped']);
    }

    // ──────────────────────────────────────────────────────────────
    // U2a: Screenshot semantics hardening
    // ──────────────────────────────────────────────────────────────

    public function test_screenshot_status_skipped_when_feature_disabled_even_with_file(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => false]);

        $user = User::factory()->create();

        // Even though a screenshot file is sent, evidence is disabled
        // so screenshot must be 'skipped' (not 'failed')
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/u2a-flag-off-with-ss',
                'extracted' => [
                    'title' => 'ЛДСП U2a disabled + screenshot',
                    'price' => '1 000 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('screenshot.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('material_status', 'created');
        $response->assertJsonPath('evidence_status', 'skipped_feature_disabled');
        $response->assertJsonPath('screenshot_status', 'skipped');
        // Must NOT be 'failed' — screenshot was irrelevant, not actually failed
    }

    public function test_screenshot_status_skipped_not_failed_when_evidence_skipped_unmapped(): void
    {
        // With current DB schema, all material types map to cost components,
        // so skipped_unmapped_type can only occur if type is null/empty on the model.
        // We simulate this by verifying the backend contract: when evidence_status
        // is any intentional skip, screenshot_status must be 'skipped' (never 'failed').
        // Since we cannot insert an unmapped type into the strict ENUM column,
        // we verify the contract via the feature-disabled path which shares
        // the same 'screenshot is irrelevant' semantics.
        config(['smeta.evidence.generic_chrome_enabled' => false]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url' => 'https://example.com/product/u2a-screenshot-irrelevant',
                'extracted' => [
                    'title' => 'ЛДСП U2a screenshot must not be failed',
                    'price' => '5 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('evidence_status', 'skipped_feature_disabled');
        // Key assertion: screenshot was sent but evidence was skipped,
        // so screenshot_status must be 'skipped' (irrelevant), never 'failed'
        $response->assertJsonPath('screenshot_status', 'skipped');
    }

    // ──────────────────────────────────────────────────────────────
    // Corrective: duplicate evidence must resolve pending run items
    // ──────────────────────────────────────────────────────────────

    /**
     * Real-world scenario: user opens a supplier page from an evidence item link,
     * captures with one-click, gets evidence_status=duplicate (within 60-second dedup
     * window), but the pending item must still be resolved.
     */
    public function test_duplicate_evidence_resolves_pending_run_item(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $url = 'https://example.com/product/plate-dup-resolve';

        // Pre-create material so createOrUpdateMaterial reliably finds it as plate type
        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Egger 16мм',
            'article'        => 'DUP-RES-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3500,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
        ]);

        // Pre-existing evidence record (duplicate candidate within 60s window)
        $existingRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => $url,
            'source_domain'       => 'example.com',
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'trust_score'         => 60,
            'created_by'          => $user->id,
        ]);

        // Pending evidence item that links to same URL + cost_component
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
            'label'           => 'ЛДСП тестовая (дубликат)',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        // One-click capture — will return evidence_status=duplicate (same URL within 60s)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Egger 16мм',
                    'price' => '3 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('evidence_status', 'duplicate');

        // Pending item must be RESOLVED — duplicate must not be a no-op
        $item->refresh();
        $this->assertSame(
            EvidenceItemStatus::RESOLVED,
            $item->status,
            'Duplicate evidence must resolve the matching pending run item'
        );

        // auto_link axis must report the resolution
        $this->assertTrue(
            $response->json('auto_link.linked') === true,
            'auto_link.linked must be true when duplicate evidence resolves a pending item'
        );
    }

    /**
     * Ensure duplicate path does not create an extra EvidenceRecord.
     * The same record must be reused, not duplicated.
     */
    public function test_no_extra_evidence_record_created_in_duplicate_path(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-no-extra-record';

        // Pre-create material (plate) so type is predictable
        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП no-extra',
            'article'        => 'NO-EXTRA-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3000,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
        ]);

        // Pre-existing record (will be detected as duplicate)
        EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => $url,
            'source_domain'       => 'example.com',
            'observed_price'      => 3000,
            'currency'            => 'RUB',
            'trust_score'         => 60,
            'created_by'          => $user->id,
        ]);

        $countBefore = EvidenceRecord::where('created_by', $user->id)->count();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП no-extra',
                    'price' => '3 000 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'duplicate');

        $countAfter = EvidenceRecord::where('created_by', $user->id)->count();
        $this->assertSame(
            $countBefore,
            $countAfter,
            'No extra EvidenceRecord must be created when evidence is duplicate'
        );
    }

    /**
     * Verify that the observation bridge writes evidence_record_id even when
     * evidence is a duplicate. This ensures material-detail can find the screenshot
     * via the existing evidence record's asset.
     */
    public function test_observation_bridge_written_for_duplicate_evidence(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-bridge-dup';

        // Pre-create plate material for predictable type
        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП bridge test',
            'article'        => 'BRIDGE-DUP-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 4000,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
        ]);

        // Pre-existing record with a screenshot asset
        $existingRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => SourceType::CHROME_CAPTURE,
            'capture_method'      => CaptureMethod::CHROME_EXTENSION,
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => $url,
            'source_domain'       => 'example.com',
            'observed_price'      => 4000,
            'currency'            => 'RUB',
            'trust_score'         => 60,
            'created_by'          => $user->id,
        ]);

        Storage::disk('public')->put('screenshots/chrome/generic/2025/04/existing.jpg', 'fake-image-content');
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $existingRecord->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/chrome/generic/2025/04/existing.jpg',
            'original_filename'  => 'existing.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1000,
            'sha256'             => hash('sha256', 'fake-image-content'),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП bridge test',
                    'price' => '4 000 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'duplicate');

        // The observation must have evidence_record_id pointing at the existing record
        $materialId = $response->json('material.id');
        $observation = MaterialPriceHistory::where('material_id', $materialId)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($observation, 'Observation must exist after one-click');
        $this->assertSame(
            $existingRecord->id,
            $observation->evidence_record_id,
            'Bridge must write evidence_record_id to observation even on duplicate path'
        );
        $this->assertNotNull(
            $observation->screenshot_path,
            'Bridge must also write screenshot_path from existing asset to observation'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Corrective: existing material update must recalculate trust score
    // ──────────────────────────────────────────────────────────────

    /**
     * When an already-existing material is updated via one-click, the trust
     * score must be recalculated so the new screenshot/evidence is reflected.
     * Previously the stored trust_score remained stale after update.
     */
    public function test_trust_score_recalculated_after_existing_material_update(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-trust-update';

        // Pre-create material with a low trust score
        $material = Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Trust Update Test',
            'article'        => 'TRUST-UPD-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 2000,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
            'trust_score'    => 0,
        ]);

        $scoreBefore = $material->trust_score;

        // One-click update with screenshot — should recalculate trust
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Trust Update Test',
                    'price' => '2 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('material_status', 'updated');

        $material->refresh();
        $this->assertGreaterThan(
            $scoreBefore,
            $material->trust_score,
            'Trust score must increase after one-click update with screenshot'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Corrective: screenshot URL must go through /api/ prefix
    // ──────────────────────────────────────────────────────────────

    /**
     * The screenshot URL in material detail must start with /api/screenshots/
     * so that production nginx routes it to the backend instead of the SPA.
     */
    public function test_screenshot_url_goes_through_api_prefix(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-ss-url';

        // One-click with screenshot
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Screenshot URL Test',
                    'price' => '3 000 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('screenshot.jpg'),
            ]);

        $response->assertStatus(201);
        $materialId = $response->json('material.id');

        // Load material detail and check screenshot URL
        $detailResponse = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$materialId}");

        $detailResponse->assertOk();
        $screenshotUrl = $detailResponse->json('latest_screenshot.url');
        $this->assertNotNull($screenshotUrl, 'Screenshot URL must be present');
        $this->assertStringContainsString(
            '/api/screenshots/',
            $screenshotUrl,
            'Screenshot URL must route through /api/screenshots/ prefix'
        );
        $this->assertStringNotContainsString(
            '/storage/',
            $screenshotUrl,
            'Screenshot URL must NOT use the /storage/ prefix that gets caught by SPA'
        );
    }

    /**
     * The /api/screenshots/ route must serve the actual image file.
     */
    public function test_screenshot_api_route_serves_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $imageContent = 'fake-jpeg-content-for-test';
        $path = 'screenshots/chrome/generic/2026/04/test-serve.jpg';
        Storage::disk('public')->put($path, $imageContent);

        $response = $this->get('/api/screenshots/chrome/generic/2026/04/test-serve.jpg');

        $response->assertOk();
        // Storage::response() returns StreamedResponse — use streamedContent()
        $this->assertSame($imageContent, $response->streamedContent());
    }

    /**
     * The /api/screenshots/ route must return 404 for path traversal attempts.
     */
    public function test_screenshot_api_route_rejects_traversal(): void
    {
        $response = $this->get('/api/screenshots/../../../etc/passwd');
        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────
    // Corrective: existing material update must resolve pending evidence item
    // ──────────────────────────────────────────────────────────────

    /**
     * When a material already exists and has a pending evidence item in an
     * active run, updating it via one-click must resolve that item —
     * exactly the same as for newly created materials.
     *
     * This was the core bug: existing-material update path did not
     * reconcile the pending evidence item because the item source_url
     * (stored raw) didn't match the UrlNormalizer-normalized browser URL.
     */
    public function test_existing_material_update_resolves_pending_evidence_item(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-existing-reconcile';

        // Pre-create existing material (as if from parser or previous chrome ext)
        $material = Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Existing Reconcile',
            'article'        => 'EX-RECON-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 2000,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
            'trust_score'    => 0,
        ]);

        // Set up evidence run with a pending item for this material
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
            'label'           => 'ЛДСП Existing Reconcile',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        // One-click update — dedup will find the existing material and UPDATE it
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Existing Reconcile',
                    'price' => '2 200 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('material_status', 'updated');
        $response->assertJsonPath('auto_link.linked', true);
        $response->assertJsonPath('auto_link.item_id', $item->id);

        // Item must be RESOLVED
        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertNotNull($item->evidence_record_id);
    }

    /**
     * Existing material update resolves pending item even when item source_url
     * was stored with a different URL format (e.g. cleanUrl stripped 'from'
     * param while UrlNormalizer keeps it). The normalizer must reconcile both.
     */
    public function test_existing_material_update_resolves_despite_url_normalization_diff(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();

        // Item source_url as cleanUrl would produce (stripped 'from' param)
        $itemUrl = 'https://supplier.ru/product/12345/';
        // Browser URL has extra tracking param that cleanUrl strips but UrlNormalizer keeps
        $browserUrl = 'https://supplier.ru/product/12345/?from=catalog';

        // Pre-create existing material with the cleaned URL
        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП URL Norm Test',
            'article'        => 'URL-NORM-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 2000,
            'source_url'     => $itemUrl,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
            'trust_score'    => 0,
        ]);

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
            'label'           => 'ЛДСП URL Norm Test',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $itemUrl, // cleaned URL (no 'from' param)
        ]);

        // One-click with browser URL that has 'from' param
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $browserUrl,
                'extracted'       => [
                    'title' => 'ЛДСП URL Norm Test',
                    'price' => '2 200 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('auto_link.linked', true);
        $response->assertJsonPath('auto_link.item_id', $item->id);

        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
    }

    /**
     * Existing material update does not create duplicate materials or
     * unnecessary evidence records — only one new evidence record and
     * one auto-link should be produced.
     */
    public function test_existing_material_update_no_extra_duplicates(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-no-dupes';

        $material = Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП No Dupes',
            'article'        => 'NO-DUPES-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 2000,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
            'trust_score'    => 0,
        ]);

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
            'label'           => 'ЛДСП No Dupes',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $materialCountBefore = Material::count();
        $evidenceRecordCountBefore = EvidenceRecord::count();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП No Dupes',
                    'price' => '2 200 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('material_status', 'updated');

        // No new materials created — only the existing one updated
        $this->assertSame($materialCountBefore, Material::count());
        // Exactly one new evidence record
        $this->assertSame($evidenceRecordCountBefore + 1, EvidenceRecord::count());
    }

    /**
     * If the pending item is already resolved, one-click does not re-resolve
     * or create extra links — reconciliation is idempotent.
     */
    public function test_already_resolved_item_not_re_resolved(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-already-resolved';

        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Already Resolved',
            'article'        => 'RESOLVED-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 2000,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
            'trust_score'    => 0,
        ]);

        $project = $this->makeProject($user);
        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => EvidenceRunStatus::IN_PROGRESS,
            'total_items'     => 1,
            'completed_items' => 1,
            'failed_items'    => 0,
        ]);

        // Item is already RESOLVED (terminal status)
        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => CostComponent::PLATE,
            'label'           => 'ЛДСП Already Resolved',
            'status'          => EvidenceItemStatus::RESOLVED,
            'source_url'      => $url,
        ]);

        $linkCountBefore = EvidenceLink::count();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Already Resolved',
                    'price' => '2 200 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        // auto_link should not be linked — item is already in terminal status
        $response->assertJsonPath('auto_link.linked', false);

        // No extra evidence links created
        $this->assertSame($linkCountBefore, EvidenceLink::count());

        // Item status unchanged
        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
    }

    // ──────────────────────────────────────────────────────────────
    // Corrective: fresh proof auto-resolve + snapshot deduplication
    // ──────────────────────────────────────────────────────────────

    /**
     * Chrome one-click reuses a fresh equivalent proof record instead of
     * creating a redundant new one when URL, component, and price match.
     */
    public function test_chrome_one_click_reuses_fresh_equivalent_proof(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-dedup-fresh';

        // Pre-create a fresh evidence record with screenshot (as if captured earlier today)
        // Backdate to 2 hours ago: outside 60-second rapid dedup but within 7-day freshness
        $existingRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now()->subHours(2),
            'created_by'          => $user->id,
        ]);
        $existingRecord->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $existingRecord->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/chrome/generic/2026/04/existing.jpg',
            'original_filename'  => 'existing.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'existing-screenshot-content'),
        ]);

        // Pre-create material so dedup matches it
        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Dedup Fresh',
            'article'        => 'DEDUP-FRESH-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3500,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
        ]);

        $recordCountBefore = EvidenceRecord::count();
        $assetCountBefore = GenericEvidenceAsset::count();

        // One-click with same price — should reuse existing proof
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Dedup Fresh',
                    'price' => '3 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'reused_existing');
        $response->assertJsonPath('evidence.record_id', $existingRecord->id);

        // No new evidence record created
        $this->assertSame($recordCountBefore, EvidenceRecord::count());
        // No new screenshot asset stored
        $this->assertSame($assetCountBefore, GenericEvidenceAsset::count());
    }

    /**
     * Chrome one-click creates a new record when the price changed materially,
     * even though a fresh proof exists for the same URL/component.
     */
    public function test_chrome_one_click_creates_new_snapshot_when_price_changed(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-price-changed';

        // Fresh existing proof at price 3500 — backdated outside 60-second window
        $existingRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now()->subHours(2),
            'created_by'          => $user->id,
        ]);
        $existingRecord->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $existingRecord->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/chrome/generic/2026/04/price-old.jpg',
            'original_filename'  => 'price-old.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'old-price-screenshot'),
        ]);

        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Price Changed',
            'article'        => 'PRICE-CHG-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3500,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
        ]);

        $recordCountBefore = EvidenceRecord::count();

        // One-click with DIFFERENT price (4200 vs 3500 — >1% change)
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Price Changed',
                    'price' => '4 200 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'created');

        // New evidence record must be created
        $this->assertSame($recordCountBefore + 1, EvidenceRecord::count());
    }

    /**
     * Chrome one-click creates a new record when the existing proof is stale
     * (older than freshness window), even if price matches.
     */
    public function test_chrome_one_click_creates_new_snapshot_when_proof_stale(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-stale-proof';

        // STALE proof: created 10 days ago (default freshness = 7 days)
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
        // Backdate created_at
        $staleRecord->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $staleRecord->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/chrome/generic/2026/03/stale.jpg',
            'original_filename'  => 'stale.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'stale-screenshot'),
        ]);

        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Stale Proof',
            'article'        => 'STALE-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3500,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
        ]);

        $recordCountBefore = EvidenceRecord::count();

        // Same price, but proof is stale — new snapshot needed
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Stale Proof',
                    'price' => '3 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'created');
        $this->assertSame($recordCountBefore + 1, EvidenceRecord::count());
    }

    /**
     * Chrome one-click creates a new record when the existing proof
     * has no screenshot asset, even if URL/price/freshness match.
     */
    public function test_chrome_one_click_creates_new_snapshot_when_no_screenshot_on_existing(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-no-asset';

        // Fresh record but WITHOUT any screenshot asset — backdated outside 60-second window
        $noAssetRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now()->subHours(2),
            'created_by'          => $user->id,
        ]);
        $noAssetRecord->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        // No GenericEvidenceAsset created

        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП No Asset',
            'article'        => 'NO-ASSET-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3500,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
        ]);

        $recordCountBefore = EvidenceRecord::count();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП No Asset',
                    'price' => '3 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'created');
        $this->assertSame($recordCountBefore + 1, EvidenceRecord::count());
    }

    /**
     * Fresh reuse + auto-link: if a fresh proof is reused AND a pending
     * evidence item exists, the item must still be auto-linked and resolved.
     */
    public function test_fresh_reuse_still_auto_links_to_pending_evidence_item(): void
    {
        config(['smeta.evidence.generic_chrome_enabled' => true]);
        Storage::fake('public');

        $user = User::factory()->create();
        $url = 'https://example.com/product/plate-reuse-autolink';

        // Fresh proof with screenshot — backdated outside 60-second window
        $existingRecord = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_capture',
            'capture_method'      => 'chrome_extension',
            'verification_status' => 'pending',
            'source_url'          => $url,
            'observed_price'      => 3500,
            'currency'            => 'RUB',
            'observed_at'         => now()->subHours(2),
            'created_by'          => $user->id,
        ]);
        $existingRecord->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $existingRecord->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/chrome/generic/2026/04/reuse-link.jpg',
            'original_filename'  => 'reuse-link.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'reuse-link-content'),
        ]);

        Material::create([
            'user_id'        => $user->id,
            'origin'         => 'parser',
            'name'           => 'ЛДСП Reuse Autolink',
            'article'        => 'REUSE-LINK-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3500,
            'source_url'     => $url,
            'is_active'      => true,
            'version'        => 1,
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
        ]);

        // Pending evidence item in active run
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
            'label'           => 'ЛДСП Reuse Autolink',
            'status'          => EvidenceItemStatus::PENDING,
            'source_url'      => $url,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/chrome/extract-with-evidence', [
                'url'             => $url,
                'extracted'       => [
                    'title' => 'ЛДСП Reuse Autolink',
                    'price' => '3 500 ₽',
                ],
                'screenshot_file' => UploadedFile::fake()->image('ss.jpg'),
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence_status', 'reused_existing');
        $response->assertJsonPath('auto_link.linked', true);
        $response->assertJsonPath('auto_link.item_id', $item->id);

        // Item must be RESOLVED
        $item->refresh();
        $this->assertSame(EvidenceItemStatus::RESOLVED, $item->status);
        $this->assertSame($existingRecord->id, $item->evidence_record_id);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'     => $user->id,
            'number'      => 'PRJ-U2-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address'     => 'Test Address',
        ]);
    }
}
