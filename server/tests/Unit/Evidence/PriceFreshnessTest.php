<?php

namespace Tests\Unit\Evidence;

use App\Evidence\CostComponent;
use App\Evidence\ResolutionType;
use App\Evidence\VerificationStatus;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Material;
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\User;
use App\Services\MaterialConfirmationService;
use App\Services\TrustScoreService;
use App\Services\UrlNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PriceFreshnessTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Project setting: default freshness days
    // ──────────────────────────────────────────────────────────────

    public function test_project_has_default_freshness_days(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        // Default from migration is 7
        $this->assertEquals(7, $project->fresh()->price_confirmation_freshness_days);
    }

    public function test_project_freshness_days_can_be_updated(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'price_confirmation_freshness_days' => 14,
            ]);

        $response->assertOk();
        $this->assertEquals(14, $project->fresh()->price_confirmation_freshness_days);
    }

    public function test_project_freshness_days_validation(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        // Too high
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'price_confirmation_freshness_days' => 999,
            ]);
        $response->assertUnprocessable();

        // Zero
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'price_confirmation_freshness_days' => 0,
            ]);
        $response->assertUnprocessable();

        // Null (clear) is allowed
        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'price_confirmation_freshness_days' => null,
            ]);
        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────
    // 2. MaterialConfirmationService: evaluate freshness
    // ──────────────────────────────────────────────────────────────

    public function test_confirmation_missing_when_no_evidence(): void
    {
        $service = app(MaterialConfirmationService::class);

        $result = $service->evaluate('https://example.com/product/123', CostComponent::PLATE);

        $this->assertEquals(MaterialConfirmationService::STATE_MISSING, $result['state']);
        $this->assertNull($result['confirmed_at']);
        $this->assertNull($result['record_id']);
    }

    public function test_confirmation_missing_when_no_source_url(): void
    {
        $service = app(MaterialConfirmationService::class);

        $result = $service->evaluate(null, CostComponent::PLATE);

        $this->assertEquals(MaterialConfirmationService::STATE_MISSING, $result['state']);
    }

    public function test_confirmation_confirmed_when_fresh_evidence_exists(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/product/fresh-plate';

        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => app(UrlNormalizer::class)->normalize($url),
            'observed_price' => 3500,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
        ]);

        // Add proof asset (screenshot)
        GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/test.jpg',
            'original_filename' => 'screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        $service = app(MaterialConfirmationService::class);
        $result = $service->evaluate($url, CostComponent::PLATE, 7);

        $this->assertEquals(MaterialConfirmationService::STATE_CONFIRMED, $result['state']);
        $this->assertNotNull($result['confirmed_at']);
        $this->assertEquals($record->id, $result['record_id']);
        $this->assertLessThanOrEqual(7, $result['days_ago']);
    }

    public function test_confirmation_stale_when_evidence_is_old(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/product/stale-plate';

        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => app(UrlNormalizer::class)->normalize($url),
            'observed_price' => 3500,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
            'observed_at' => now()->subDays(30),
        ]);
        // Force created_at to old date
        $record->forceFill(['created_at' => now()->subDays(30)])->save();

        GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/old.jpg',
            'original_filename' => 'screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        $service = app(MaterialConfirmationService::class);
        $result = $service->evaluate($url, CostComponent::PLATE, 7);

        $this->assertEquals(MaterialConfirmationService::STATE_STALE, $result['state']);
        $this->assertGreaterThan(7, $result['days_ago']);
        $this->assertEquals($record->id, $result['record_id']);
    }

    public function test_confirmation_missing_when_no_screenshot_asset(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/product/no-screenshot';

        EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => app(UrlNormalizer::class)->normalize($url),
            'observed_price' => 3500,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
        ]);
        // No asset → missing

        $service = app(MaterialConfirmationService::class);
        $result = $service->evaluate($url, CostComponent::PLATE, 7);

        $this->assertEquals(MaterialConfirmationService::STATE_MISSING, $result['state']);
    }

    // ──────────────────────────────────────────────────────────────
    // 3. ResolutionType: AUTO_FRESH exists
    // ──────────────────────────────────────────────────────────────

    public function test_auto_fresh_resolution_type_exists(): void
    {
        $this->assertEquals('auto_fresh', ResolutionType::AUTO_FRESH);
        $this->assertContains(ResolutionType::AUTO_FRESH, ResolutionType::all());
    }

    // ──────────────────────────────────────────────────────────────
    // 4. EvidenceRunItemCollector: freshness auto-resolve
    // ──────────────────────────────────────────────────────────────

    public function test_get_fresh_record_returns_record_when_fresh(): void
    {
        $user = User::factory()->create();
        $url = 'https://materialshop.com/plate-abc';

        $normalizedUrl = app(UrlNormalizer::class)->normalize($url);
        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => $normalizedUrl,
            'observed_price' => 3000,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
        ]);

        GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/fresh.jpg',
            'original_filename' => 'screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        $service = app(MaterialConfirmationService::class);
        $freshRecord = $service->getFreshRecord($url, CostComponent::PLATE, 7);

        $this->assertNotNull($freshRecord);
        $this->assertEquals($record->id, $freshRecord->id);
    }

    public function test_get_fresh_record_returns_null_when_stale(): void
    {
        $user = User::factory()->create();
        $url = 'https://materialshop.com/plate-stale';

        $normalizedUrl = app(UrlNormalizer::class)->normalize($url);
        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => $normalizedUrl,
            'observed_price' => 3000,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
            'observed_at' => now()->subDays(30),
        ]);
        $record->forceFill(['created_at' => now()->subDays(30)])->save();

        GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/stale.jpg',
            'original_filename' => 'screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        $service = app(MaterialConfirmationService::class);
        $freshRecord = $service->getFreshRecord($url, CostComponent::PLATE, 7);

        $this->assertNull($freshRecord);
    }

    public function test_collector_uses_auto_fresh_resolution_type(): void
    {
        // Verify the constant is wired in the EvidenceRunItemCollector source
        $source = file_get_contents(app_path('Services/EvidenceRunItemCollector.php'));

        $this->assertStringContainsString('ResolutionType::AUTO_FRESH', $source);
        $this->assertStringContainsString('confirmationService->getFreshRecord', $source);
    }

    // ──────────────────────────────────────────────────────────────
    // 5. MaterialCatalogController: confirmation_state in response
    // ──────────────────────────────────────────────────────────────

    public function test_material_detail_includes_confirmation_state(): void
    {
        $user = User::factory()->create();
        $material = Material::create([
            'user_id' => $user->id,
            'name' => 'Test Material',
            'article' => 'TEST-PL-002',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 5000,
            'source_url' => 'https://example.com/plate-detail',
            'origin' => 'user',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}");

        $response->assertOk();
        // confirmation_state should be present (as null or object)
        $this->assertArrayHasKey('confirmation_state', $response->json());
    }

    public function test_material_detail_confirmation_confirmed_with_fresh_evidence(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/plate-confirmed';

        $material = Material::create([
            'user_id' => $user->id,
            'name' => 'Confirmed Plate',
            'article' => 'TEST-PL-003',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 5000,
            'source_url' => $url,
            'origin' => 'user',
            'visibility' => 'private',
        ]);

        $normalizedUrl = app(UrlNormalizer::class)->normalize($url);
        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => $normalizedUrl,
            'observed_price' => 5000,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
        ]);

        GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/confirmed.jpg',
            'original_filename' => 'screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}");

        $response->assertOk();
        $response->assertJsonPath('confirmation_state.state', 'confirmed');
        $this->assertNotNull($response->json('confirmation_state.confirmed_at'));
    }

    // ──────────────────────────────────────────────────────────────
    // 6. Project freshness wiring: project setting affects material detail
    // ──────────────────────────────────────────────────────────────

    public function test_material_detail_uses_project_freshness_days(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/plate-project-freshness';

        $material = Material::create([
            'user_id' => $user->id,
            'name' => 'Project Freshness Plate',
            'article' => 'TEST-PF-001',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 4000,
            'source_url' => $url,
            'origin' => 'user',
            'visibility' => 'private',
        ]);

        $normalizedUrl = app(UrlNormalizer::class)->normalize($url);
        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => CostComponent::PLATE,
            'source_type' => 'chrome_ext',
            'capture_method' => 'viewport',
            'verification_status' => VerificationStatus::AUTO_VERIFIED,
            'source_url' => $normalizedUrl,
            'observed_price' => 4000,
            'currency' => 'RUB',
            'trust_score' => 80,
            'created_by' => $user->id,
            'observed_at' => now()->subDays(20),
        ]);
        $record->forceFill(['created_at' => now()->subDays(20)])->save();

        GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'screenshot',
            'file_path' => 'screenshots/project-fresh.jpg',
            'original_filename' => 'screenshot.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
        ]);

        // Without project_id → default 7 days → should be stale (20 days old)
        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}");

        $response->assertOk();
        $response->assertJsonPath('confirmation_state.state', 'stale');

        // With project that has freshness_days=30 → should be confirmed (20 < 30)
        $project = $this->makeProject($user);
        $project->update(['price_confirmation_freshness_days' => 30]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}?project_id={$project->id}");

        $response->assertOk();
        $response->assertJsonPath('confirmation_state.state', 'confirmed');
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Not-applicable states
    // ──────────────────────────────────────────────────────────────

    public function test_material_detail_returns_not_applicable_when_no_source_url(): void
    {
        $user = User::factory()->create();

        $material = Material::create([
            'user_id' => $user->id,
            'name' => 'No URL Plate',
            'article' => 'TEST-NA-001',
            'type' => 'plate',
            'unit' => 'м²',
            'price_per_unit' => 3000,
            'source_url' => null,
            'origin' => 'user',
            'visibility' => 'private',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/materials/catalog/{$material->id}");

        $response->assertOk();
        $response->assertJsonPath('confirmation_state.state', 'not_applicable');
        $response->assertJsonPath('confirmation_state.reason', 'no_source_url');
    }

    public function test_material_detail_returns_not_applicable_when_unmapped_type(): void
    {
        // All valid DB types (plate/edge/facade/hardware) are mapped, so we test
        // the controller method directly to verify defensive handling.
        $controller = app(\App\Http\Controllers\Api\MaterialCatalogController::class);

        $material = new Material();
        $material->type = 'plate';
        $material->source_url = 'https://example.com/test';

        // Use reflection to test the protected method with a temporarily modified type
        $method = new \ReflectionMethod($controller, 'resolveConfirmationState');
        $method->setAccessible(true);

        // Modify the type field in memory only (not persisted)
        $material->setAttribute('type', 'unknown_future_type');
        $result = $method->invoke($controller, $material, null);

        $this->assertEquals('not_applicable', $result['state']);
        $this->assertEquals('type_unmapped', $result['reason']);
    }

    // ──────────────────────────────────────────────────────────────
    // 8. TrustScoreService: +10 screenshot criterion aligned with
    //    MaterialCatalogController::computeTrustBreakdown
    // ──────────────────────────────────────────────────────────────

    /**
     * When a material's latest observation has evidence_record_id pointing to a
     * GenericEvidenceAsset with asset_type='screenshot', TrustScoreService must
     * award the +10 snapshot bonus — even if observation.screenshot_path is null.
     * This aligns recalculate() with computeTrustBreakdown() in MaterialCatalogController.
     */
    public function test_trust_score_snapshot_criterion_uses_evidence_record_id_fallback(): void
    {
        $user = User::factory()->create();

        $material = Material::create([
            'user_id'        => $user->id,
            'name'           => 'TrustScore Fallback Plate',
            'article'        => 'TS-FALLBACK-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3000,
            'source_url'     => 'https://example.com/trust-fallback',
            'origin'         => 'parser',
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
        ]);

        $record = EvidenceRecord::create([
            'uuid'                => (string) Str::uuid(),
            'cost_component'      => CostComponent::PLATE,
            'source_type'         => 'chrome_ext',
            'capture_method'      => 'viewport',
            'verification_status' => VerificationStatus::PENDING,
            'source_url'          => 'https://example.com/trust-fallback',
            'observed_price'      => 3000,
            'currency'            => 'RUB',
            'trust_score'         => 50,
            'created_by'          => $user->id,
        ]);

        // Observation: evidence_record_id set but screenshot_path/snapshot_path = null
        MaterialPriceHistory::create([
            'material_id'        => $material->id,
            'price'              => 3000,
            'price_per_unit'     => 3000,
            'currency'           => 'RUB',
            'source_url'         => 'https://example.com/trust-fallback',
            'observed_at'        => now(),
            'valid_from'         => now(),
            'version'            => 1,
            'evidence_record_id' => $record->id,
            'screenshot_path'    => null,
        ]);

        // Without screenshot asset → no +10
        $scoreNoAsset = app(TrustScoreService::class)->recalculate($material)->trust_score;

        // Create screenshot asset for the evidence record
        GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'screenshots/chrome/generic/trust-test.jpg',
            'original_filename'  => 'trust-test.jpg',
            'mime_type'          => 'image/jpeg',
            'file_size'          => 1024,
            'sha256'             => hash('sha256', 'fake'),
        ]);

        // With screenshot asset → +10 bonus via evidence_record_id fallback
        $scoreWithAsset = app(TrustScoreService::class)->recalculate($material->fresh())->trust_score;

        $this->assertSame(
            $scoreNoAsset + 10,
            $scoreWithAsset,
            'TrustScoreService must award +10 when GenericEvidenceAsset screenshot exists for evidence_record_id'
        );
    }

    /**
     * Counterpart: when observation has direct screenshot_path set (no fallback needed),
     * TrustScoreService still awards +10 — no regression.
     */
    public function test_trust_score_snapshot_criterion_direct_screenshot_path_still_works(): void
    {
        $user = User::factory()->create();

        $material = Material::create([
            'user_id'        => $user->id,
            'name'           => 'TrustScore Direct Screenshot',
            'article'        => 'TS-DIRECT-001',
            'type'           => 'plate',
            'unit'           => 'м²',
            'price_per_unit' => 3000,
            'source_url'     => 'https://example.com/trust-direct',
            'origin'         => 'parser',
            'visibility'     => 'private',
            'data_origin'    => 'chrome_ext',
            'trust_level'    => 'unverified',
        ]);

        // Without screenshot
        MaterialPriceHistory::create([
            'material_id'     => $material->id,
            'price'           => 3000,
            'price_per_unit'  => 3000,
            'currency'        => 'RUB',
            'source_url'      => 'https://example.com/trust-direct',
            'observed_at'     => now(),
            'valid_from'      => now(),
            'version'         => 1,
            'screenshot_path' => null,
        ]);
        $scoreNoSs = app(TrustScoreService::class)->recalculate($material)->trust_score;

        // With direct screenshot_path on observation
        MaterialPriceHistory::where('material_id', $material->id)
            ->update(['screenshot_path' => 'screenshots/direct.jpg']);

        $scoreWithSs = app(TrustScoreService::class)->recalculate($material->fresh())->trust_score;

        $this->assertSame(
            $scoreNoSs + 10,
            $scoreWithSs,
            'Direct screenshot_path on observation must still award +10 (no regression)'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id,
            'number' => 'PRJ-PF-' . Str::random(4),
            'expert_name' => 'Test Expert',
            'address' => 'Test Address',
        ]);
    }
}
