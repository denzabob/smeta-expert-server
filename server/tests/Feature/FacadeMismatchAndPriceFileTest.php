<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Material;
use App\Models\MaterialPrice;
use App\Models\PriceList;
use App\Models\PriceListVersion;
use App\Models\Project;
use App\Models\ProjectPosition;
use App\Models\ProjectPositionPriceQuote;
use App\Models\ProjectRevision;
use App\Models\RevisionPublication;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class FacadeMismatchAndPriceFileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;
    private Material $facadeMaterial;
    private Supplier $supplier;
    private PriceList $priceList;
    private PriceListVersion $version;
    private MaterialPrice $price1;
    private MaterialPrice $price2;
    private MaterialPrice $price3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->project = Project::factory()->create([
            'user_id' => $this->user->id,
        ]);

        // Supplier
        $this->supplier = Supplier::create([
            'name' => 'Test Supplier',
            'is_active' => true,
        ]);

        // Price list + version
        $this->priceList = PriceList::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Test Price List',
            'type' => 'materials',
            'is_active' => true,
        ]);

        $this->version = PriceListVersion::create([
            'price_list_id' => $this->priceList->id,
            'version_number' => 1,
            'source_type' => 'file',
            'file_path' => 'price-lists/test.xlsx',
            'storage_disk' => 'local',
            'original_filename' => 'test_price_list.xlsx',
            'sha256' => hash('sha256', 'test-content'),
            'effective_date' => now(),
            'captured_at' => now(),
            'status' => 'active',
        ]);

        // Facade material (canonical)
        $this->facadeMaterial = Material::create([
            'name' => 'Фасад MDF ПВХ 16мм',
            'type' => Material::TYPE_FACADE,
            'unit' => 'м²',
            'article' => 'FACADE:test123',
            'is_active' => true,
            'facade_class' => 'STANDARD',
            'facade_base_type' => 'mdf',
            'facade_thickness_mm' => 16,
            'facade_covering' => 'pvc_film',
            'facade_cover_type' => 'gloss',
        ]);

        // Create 3 quotes — one with different thickness (extended mode material)
        $extendedMaterial = Material::create([
            'name' => 'Фасад MDF ПВХ 19мм',
            'type' => Material::TYPE_FACADE,
            'unit' => 'м²',
            'article' => 'FACADE:test456',
            'is_active' => true,
            'facade_class' => 'PREMIUM',
            'facade_base_type' => 'mdf',
            'facade_thickness_mm' => 19,
            'facade_covering' => 'pvc_film',
            'facade_cover_type' => 'matte',
        ]);

        $this->price1 = MaterialPrice::create([
            'material_id' => $this->facadeMaterial->id,
            'price_list_version_id' => $this->version->id,
            'supplier_id' => $this->supplier->id,
            'price_per_internal_unit' => 3500.00,
            'currency' => 'RUB',
        ]);

        $this->price2 = MaterialPrice::create([
            'material_id' => $this->facadeMaterial->id,
            'price_list_version_id' => $this->version->id,
            'supplier_id' => $this->supplier->id,
            'price_per_internal_unit' => 4000.00,
            'currency' => 'RUB',
        ]);

        // Extended mode quote — different material (different thickness)
        $this->price3 = MaterialPrice::create([
            'material_id' => $extendedMaterial->id,
            'price_list_version_id' => $this->version->id,
            'supplier_id' => $this->supplier->id,
            'price_per_internal_unit' => 4500.00,
            'currency' => 'RUB',
        ]);
    }

    // ========================================================
    // 1. MISMATCH FLAGS PERSISTENCE
    // ========================================================

    /**
     * Test: Extended mode saves mismatch_flags to DB
     */
    public function test_extended_mode_saves_mismatch_flags(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/api/projects/{$this->project->id}/positions", [
            'kind' => 'facade',
            'facade_material_id' => $this->facadeMaterial->id,
            'width' => 600,
            'length' => 900,
            'quantity' => 1,
            'price_method' => 'mean',
            'quote_material_price_ids' => [
                $this->price1->id,
                $this->price2->id,
                $this->price3->id,
            ],
            'quote_mismatch_flags' => [
                $this->price1->id => [],  // strict match — no flags
                $this->price2->id => [],  // strict match — no flags
                $this->price3->id => ['facade_thickness_mm', 'facade_cover_type', 'facade_class'],
            ],
        ]);

        $response->assertStatus(201);
        $positionId = $response->json('id');

        // Check DB records
        $quotes = ProjectPositionPriceQuote::where('project_position_id', $positionId)->get();
        $this->assertCount(3, $quotes);

        // price1 and price2 should have NULL mismatch_flags (strict)
        $q1 = $quotes->firstWhere('material_price_id', $this->price1->id);
        $this->assertNull($q1->mismatch_flags);

        $q2 = $quotes->firstWhere('material_price_id', $this->price2->id);
        $this->assertNull($q2->mismatch_flags);

        // price3 should have mismatch_flags set
        $q3 = $quotes->firstWhere('material_price_id', $this->price3->id);
        $this->assertNotNull($q3->mismatch_flags);
        $this->assertIsArray($q3->mismatch_flags);
        $this->assertContains('facade_thickness_mm', $q3->mismatch_flags);
        $this->assertContains('facade_cover_type', $q3->mismatch_flags);
        $this->assertContains('facade_class', $q3->mismatch_flags);
    }

    /**
     * Test: Strict mode has NULL mismatch_flags
     */
    public function test_strict_mode_mismatch_flags_null(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/api/projects/{$this->project->id}/positions", [
            'kind' => 'facade',
            'facade_material_id' => $this->facadeMaterial->id,
            'width' => 600,
            'length' => 900,
            'quantity' => 1,
            'price_method' => 'mean',
            'quote_material_price_ids' => [
                $this->price1->id,
                $this->price2->id,
            ],
            // No quote_mismatch_flags — strict mode
        ]);

        $response->assertStatus(201);
        $positionId = $response->json('id');

        $quotes = ProjectPositionPriceQuote::where('project_position_id', $positionId)->get();
        $this->assertCount(2, $quotes);

        // All should have NULL mismatch_flags
        foreach ($quotes as $q) {
            $this->assertNull($q->mismatch_flags);
        }
    }

    /**
     * Test: mismatch_flags are stored as snapshot (persist once, read from DB)
     */
    public function test_mismatch_flags_are_immutable_snapshot(): void
    {
        $this->actingAs($this->user);

        // Create position with extended quotes
        $response = $this->postJson("/api/projects/{$this->project->id}/positions", [
            'kind' => 'facade',
            'facade_material_id' => $this->facadeMaterial->id,
            'width' => 600,
            'length' => 900,
            'quantity' => 1,
            'price_method' => 'mean',
            'quote_material_price_ids' => [
                $this->price1->id,
                $this->price3->id,
            ],
            'quote_mismatch_flags' => [
                $this->price3->id => ['facade_thickness_mm'],
            ],
        ]);

        $response->assertStatus(201);
        $positionId = $response->json('id');

        // Read mismatch_flags from DB
        $q3 = ProjectPositionPriceQuote::where('project_position_id', $positionId)
            ->where('material_price_id', $this->price3->id)
            ->first();

        $this->assertEquals(['facade_thickness_mm'], $q3->mismatch_flags);

        // The snapshot should be stable — reading it again should give same result
        $q3->refresh();
        $this->assertEquals(['facade_thickness_mm'], $q3->mismatch_flags);
    }

    // ========================================================
    // 2. PUBLIC PRICE FILE DOWNLOAD
    // ========================================================

    /**
     * Test: Valid document_token allows file download
     */
    public function test_price_file_download_with_valid_token(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('price-lists/test.xlsx', 'fake-excel-content');

        // Update version to point to fake storage
        $this->version->update([
            'file_path' => 'price-lists/test.xlsx',
            'storage_disk' => 'local',
        ]);

        // Create a position with quotes referencing this version
        $position = ProjectPosition::create([
            'project_id' => $this->project->id,
            'kind' => 'facade',
            'facade_material_id' => $this->facadeMaterial->id,
            'width' => 600,
            'length' => 900,
            'quantity' => 1,
            'price_per_m2' => 3750.00,
            'price_method' => 'mean',
        ]);

        ProjectPositionPriceQuote::create([
            'project_position_id' => $position->id,
            'material_price_id' => $this->price1->id,
            'price_list_version_id' => $this->version->id,
            'supplier_id' => $this->supplier->id,
            'price_per_m2_snapshot' => 3500.00,
            'captured_at' => now(),
        ]);

        // Create revision + publication
        $revision = ProjectRevision::create([
            'project_id' => $this->project->id,
            'number' => 1,
            'status' => 'published',
            'snapshot_json' => json_encode(['test' => true]),
            'snapshot_hash' => hash('sha256', 'test'),
        ]);

        $publication = RevisionPublication::create([
            'project_revision_id' => $revision->id,
            'public_id' => 'testtoken123',
            'is_active' => true,
            'access_level' => 'public_readonly',
        ]);

        // Request file download — should succeed
        $response = $this->get("/public/price-file/{$this->version->id}/testtoken123");
        $response->assertStatus(200);
    }

    /**
     * Test: Invalid document_token returns 403
     */
    public function test_price_file_download_with_invalid_token(): void
    {
        $response = $this->get("/public/price-file/{$this->version->id}/invalidtoken999");
        $response->assertStatus(403);
    }

    /**
     * Test: Version not used in document returns 403
     */
    public function test_price_file_download_unused_version_returns_403(): void
    {
        // Create another version NOT used in any position
        $unusedVersion = PriceListVersion::create([
            'price_list_id' => $this->priceList->id,
            'version_number' => 99,
            'source_type' => 'file',
            'file_path' => 'price-lists/unused.xlsx',
            'storage_disk' => 'local',
            'original_filename' => 'unused.xlsx',
            'sha256' => hash('sha256', 'unused'),
            'effective_date' => now(),
            'captured_at' => now(),
            'status' => 'active',
        ]);

        // Create revision + publication (but NO quotes referencing unused version)
        $revision = ProjectRevision::create([
            'project_id' => $this->project->id,
            'number' => 1,
            'status' => 'published',
            'snapshot_json' => json_encode(['test' => true]),
            'snapshot_hash' => hash('sha256', 'test2'),
        ]);

        RevisionPublication::create([
            'project_revision_id' => $revision->id,
            'public_id' => 'validtoken456',
            'is_active' => true,
            'access_level' => 'public_readonly',
        ]);

        // Request file for unused version — should fail
        $response = $this->get("/public/price-file/{$unusedVersion->id}/validtoken456");
        $response->assertStatus(403);
    }

    /**
     * Test: Expired publication returns 403
     */
    public function test_price_file_download_expired_publication(): void
    {
        $revision = ProjectRevision::create([
            'project_id' => $this->project->id,
            'number' => 1,
            'status' => 'published',
            'snapshot_json' => json_encode(['test' => true]),
            'snapshot_hash' => hash('sha256', 'test3'),
        ]);

        RevisionPublication::create([
            'project_revision_id' => $revision->id,
            'public_id' => 'expiredtoken',
            'is_active' => true,
            'access_level' => 'public_readonly',
            'expires_at' => now()->subDay(),  // Expired
        ]);

        $response = $this->get("/public/price-file/{$this->version->id}/expiredtoken");
        $response->assertStatus(403);
    }

    // ========================================================
    // 3. MISMATCH FLAGS IN PDF/VERIFICATION DATA
    // ========================================================

    /**
     * Test: SmetaCalculator includes mismatch_flags in quote evidence
     */
    public function test_smeta_calculator_includes_mismatch_flags(): void
    {
        $position = ProjectPosition::create([
            'project_id' => $this->project->id,
            'kind' => 'facade',
            'facade_material_id' => $this->facadeMaterial->id,
            'width' => 600,
            'length' => 900,
            'quantity' => 2,
            'price_per_m2' => 4000.00,
            'price_method' => 'mean',
            'price_sources_count' => 2,
            'price_min' => 3500.00,
            'price_max' => 4500.00,
        ]);

        ProjectPositionPriceQuote::create([
            'project_position_id' => $position->id,
            'material_price_id' => $this->price1->id,
            'price_list_version_id' => $this->version->id,
            'supplier_id' => $this->supplier->id,
            'price_per_m2_snapshot' => 3500.00,
            'captured_at' => now(),
            'mismatch_flags' => null,  // strict
        ]);

        ProjectPositionPriceQuote::create([
            'project_position_id' => $position->id,
            'material_price_id' => $this->price3->id,
            'price_list_version_id' => $this->version->id,
            'supplier_id' => $this->supplier->id,
            'price_per_m2_snapshot' => 4500.00,
            'captured_at' => now(),
            'mismatch_flags' => ['facade_thickness_mm', 'facade_class'],
        ]);

        $calculator = app(\App\Services\Smeta\SmetaCalculator::class);
        $facadeData = $calculator->calculateFacadeData($this->project);

        $this->assertNotEmpty($facadeData);
        $firstGroup = $facadeData[0];
        $this->assertNotEmpty($firstGroup['position_details']);

        $posDetail = $firstGroup['position_details'][0];
        $this->assertNotEmpty($posDetail['quotes']);
        $this->assertCount(2, $posDetail['quotes']);

        // First quote — no mismatch
        $this->assertNull($posDetail['quotes'][0]['mismatch_flags']);

        // Second quote — has mismatch
        $this->assertNotNull($posDetail['quotes'][1]['mismatch_flags']);
        $this->assertContains('facade_thickness_mm', $posDetail['quotes'][1]['mismatch_flags']);
    }
}
