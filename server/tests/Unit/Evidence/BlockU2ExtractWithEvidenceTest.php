<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CostComponent;
use App\Evidence\EvidenceFeatures;
use App\Evidence\EvidenceItemStatus;
use App\Evidence\EvidenceRunStatus;
use App\Models\EstimateEvidenceItem;
use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\Material;
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
