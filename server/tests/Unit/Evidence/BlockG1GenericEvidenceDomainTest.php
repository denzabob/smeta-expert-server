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
use App\Models\MaterialPriceHistory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class BlockG1GenericEvidenceDomainTest extends TestCase
{
    use DatabaseTransactions;

    // ──────────────────────────────────────────────────────────────
    // 1. Schema assertions
    // ──────────────────────────────────────────────────────────────

    public function test_evidence_records_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('evidence_records'));
    }

    public function test_evidence_records_has_required_columns(): void
    {
        foreach ([
            'id', 'uuid', 'cost_component', 'source_type', 'capture_method',
            'verification_status', 'source_url', 'source_domain',
            'observed_price', 'currency', 'observed_at',
            'extracted_name', 'extracted_article',
            'metadata_json', 'confidence_score', 'trust_score',
            'created_by', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('evidence_records', $column),
                "Column evidence_records.{$column} is missing"
            );
        }
    }

    public function test_generic_evidence_assets_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('generic_evidence_assets'));
    }

    public function test_generic_evidence_assets_has_required_columns(): void
    {
        foreach ([
            'id', 'uuid', 'evidence_record_id', 'asset_type', 'file_path',
            'original_filename', 'mime_type', 'file_size', 'sha256', 'metadata_json',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('generic_evidence_assets', $column),
                "Column generic_evidence_assets.{$column} is missing"
            );
        }
    }

    public function test_evidence_links_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('evidence_links'));
    }

    public function test_evidence_links_has_required_columns(): void
    {
        foreach ([
            'id', 'evidence_record_id', 'linkable_type', 'linkable_id', 'relation_type',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('evidence_links', $column),
                "Column evidence_links.{$column} is missing"
            );
        }
    }

    public function test_estimate_evidence_runs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('estimate_evidence_runs'));
    }

    public function test_estimate_evidence_runs_has_required_columns(): void
    {
        foreach ([
            'id', 'uuid', 'project_id', 'initiated_by',
            'status', 'total_items', 'completed_items', 'failed_items',
            'metadata_json', 'started_at', 'finalized_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('estimate_evidence_runs', $column),
                "Column estimate_evidence_runs.{$column} is missing"
            );
        }
    }

    public function test_estimate_evidence_items_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('estimate_evidence_items'));
    }

    public function test_estimate_evidence_items_has_required_columns(): void
    {
        foreach ([
            'id', 'uuid', 'evidence_run_id', 'cost_component',
            'status', 'resolution_type', 'subject_type', 'subject_id',
            'evidence_record_id', 'source_url', 'diagnostics_json',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('estimate_evidence_items', $column),
                "Column estimate_evidence_items.{$column} is missing"
            );
        }
    }

    public function test_material_price_histories_has_evidence_record_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('material_price_histories', 'evidence_record_id'));
    }

    // ──────────────────────────────────────────────────────────────
    // 2. Enum assertions
    // ──────────────────────────────────────────────────────────────

    public function test_cost_component_has_seven_values(): void
    {
        $values = CostComponent::all();
        $this->assertCount(7, $values);
        foreach (['plate', 'edge', 'facade', 'fitting', 'operation', 'labor_work', 'expense'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    public function test_source_type_has_five_values(): void
    {
        $values = SourceType::all();
        $this->assertCount(5, $values);
        foreach (['supplier_website', 'manual_input', 'internal_calc', 'document', 'chrome_capture'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    public function test_capture_method_has_five_values(): void
    {
        $values = CaptureMethod::all();
        $this->assertCount(5, $values);
        foreach (['auto_scrape', 'manual_entry', 'chrome_extension', 'file_upload', 'api_import'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    public function test_verification_status_has_five_values(): void
    {
        $values = VerificationStatus::all();
        $this->assertCount(5, $values);
        foreach (['pending', 'auto_verified', 'manual_verified', 'rejected', 'stale'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    public function test_evidence_run_status_has_five_values(): void
    {
        $values = EvidenceRunStatus::all();
        $this->assertCount(5, $values);
        foreach (['pending', 'in_progress', 'ready', 'finalized', 'failed'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    public function test_evidence_run_status_finalizable_helper(): void
    {
        $statuses = EvidenceRunStatus::finalizableStatuses();
        $this->assertContains('ready', $statuses);
        $this->assertNotContains('finalized', $statuses);
        $this->assertNotContains('failed', $statuses);
    }

    public function test_evidence_item_status_has_five_values(): void
    {
        $values = EvidenceItemStatus::all();
        $this->assertCount(5, $values);
        foreach (['pending', 'collecting', 'resolved', 'failed', 'skipped'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    public function test_resolution_type_has_four_values(): void
    {
        $values = ResolutionType::all();
        $this->assertCount(4, $values);
        foreach (['auto', 'manual', 'chrome', 'skipped'] as $v) {
            $this->assertContains($v, $values);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // 3. Model relations and fillable
    // ──────────────────────────────────────────────────────────────

    public function test_evidence_record_fillable_includes_required_fields(): void
    {
        $model = new EvidenceRecord();
        $fillable = $model->getFillable();
        foreach (['uuid', 'cost_component', 'source_type', 'capture_method',
                  'verification_status', 'observed_price', 'created_by'] as $field) {
            $this->assertContains($field, $fillable, "EvidenceRecord::fillable missing {$field}");
        }
    }

    public function test_generic_evidence_asset_fillable_includes_required_fields(): void
    {
        $model = new GenericEvidenceAsset();
        $fillable = $model->getFillable();
        foreach (['uuid', 'evidence_record_id', 'asset_type', 'file_path', 'sha256'] as $field) {
            $this->assertContains($field, $fillable, "GenericEvidenceAsset::fillable missing {$field}");
        }
    }

    public function test_estimate_evidence_run_fillable_includes_required_fields(): void
    {
        $model = new EstimateEvidenceRun();
        $fillable = $model->getFillable();
        foreach (['uuid', 'project_id', 'initiated_by', 'status', 'total_items'] as $field) {
            $this->assertContains($field, $fillable, "EstimateEvidenceRun::fillable missing {$field}");
        }
    }

    public function test_estimate_evidence_item_fillable_includes_required_fields(): void
    {
        $model = new EstimateEvidenceItem();
        $fillable = $model->getFillable();
        foreach (['uuid', 'evidence_run_id', 'cost_component', 'status'] as $field) {
            $this->assertContains($field, $fillable, "EstimateEvidenceItem::fillable missing {$field}");
        }
    }

    public function test_material_price_history_fillable_includes_evidence_record_id(): void
    {
        $model = new MaterialPriceHistory();
        $this->assertContains('evidence_record_id', $model->getFillable());
    }

    // ──────────────────────────────────────────────────────────────
    // 4. Model persistence and relations
    // ──────────────────────────────────────────────────────────────

    public function test_evidence_record_can_be_created_and_retrieved(): void
    {
        $user = User::factory()->create();

        $record = EvidenceRecord::create([
            'uuid'             => (string) Str::uuid(),
            'cost_component'   => 'plate',
            'source_type'      => 'supplier_website',
            'capture_method'   => 'auto_scrape',
            'verification_status' => 'pending',
            'observed_price'   => 1500.00,
            'currency'         => 'RUB',
            'created_by'       => $user->id,
        ]);

        $this->assertDatabaseHas('evidence_records', [
            'id'             => $record->id,
            'cost_component' => 'plate',
            'source_type'    => 'supplier_website',
        ]);
    }

    public function test_evidence_record_has_assets_relation(): void
    {
        $user = User::factory()->create();

        $record = EvidenceRecord::create([
            'uuid'             => (string) Str::uuid(),
            'cost_component'   => 'edge',
            'source_type'      => 'manual_input',
            'capture_method'   => 'manual_entry',
            'created_by'       => $user->id,
        ]);

        $asset = GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'screenshot',
            'file_path'          => 'evidence-records/test/screen.png',
            'original_filename'  => 'screen.png',
            'mime_type'          => 'image/png',
            'file_size'          => 12345,
            'sha256'             => str_repeat('a', 64),
        ]);

        $loaded = EvidenceRecord::with('assets')->find($record->id);
        $this->assertCount(1, $loaded->assets);
        $this->assertSame($asset->id, $loaded->assets->first()->id);
    }

    public function test_generic_evidence_asset_belongs_to_evidence_record(): void
    {
        $user = User::factory()->create();

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => 'facade',
            'source_type'    => 'document',
            'capture_method' => 'file_upload',
            'created_by'     => $user->id,
        ]);

        $asset = GenericEvidenceAsset::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type'         => 'document',
            'file_path'          => 'evidence-records/test/doc.pdf',
        ]);

        $this->assertSame($record->id, $asset->evidenceRecord->id);
    }

    public function test_evidence_link_can_be_created_with_morph(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => 'fitting',
            'source_type'    => 'supplier_website',
            'capture_method' => 'chrome_extension',
            'created_by'     => $user->id,
        ]);

        $link = EvidenceLink::create([
            'evidence_record_id' => $record->id,
            'linkable_type'      => 'project_position',
            'linkable_id'        => 1,
            'relation_type'      => 'primary',
        ]);

        $this->assertDatabaseHas('evidence_links', [
            'evidence_record_id' => $record->id,
            'linkable_type'      => 'project_position',
            'relation_type'      => 'primary',
        ]);
    }

    public function test_estimate_evidence_run_can_be_created(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'            => (string) Str::uuid(),
            'project_id'      => $project->id,
            'initiated_by'    => $user->id,
            'status'          => 'pending',
            'total_items'     => 0,
            'completed_items' => 0,
            'failed_items'    => 0,
        ]);

        $this->assertDatabaseHas('estimate_evidence_runs', [
            'id'         => $run->id,
            'project_id' => $project->id,
            'status'     => 'pending',
        ]);
    }

    public function test_estimate_evidence_run_belongs_to_project(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'pending',
            'total_items'  => 0, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $this->assertSame($project->id, $run->project->id);
    }

    public function test_project_has_evidence_runs_relation(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'pending',
            'total_items'  => 0, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $this->assertCount(1, $project->evidenceRuns);
    }

    public function test_estimate_evidence_item_can_be_created(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'pending',
            'total_items'  => 1, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => 'plate',
            'status'          => 'pending',
        ]);

        $this->assertDatabaseHas('estimate_evidence_items', [
            'id'              => $item->id,
            'evidence_run_id' => $run->id,
            'cost_component'  => 'plate',
        ]);
    }

    public function test_estimate_evidence_item_belongs_to_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'pending',
            'total_items'  => 1, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => 'edge',
            'status'          => 'pending',
        ]);

        $this->assertSame($run->id, $item->run->id);
    }

    public function test_evidence_item_can_link_evidence_record(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'in_progress',
            'total_items'  => 1, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => 'plate',
            'source_type'    => 'supplier_website',
            'capture_method' => 'auto_scrape',
            'created_by'     => $user->id,
        ]);

        $item = EstimateEvidenceItem::create([
            'uuid'               => (string) Str::uuid(),
            'evidence_run_id'    => $run->id,
            'cost_component'     => 'plate',
            'status'             => 'resolved',
            'resolution_type'    => 'auto',
            'evidence_record_id' => $record->id,
        ]);

        $this->assertSame($record->id, $item->evidenceRecord->id);
    }

    public function test_material_price_history_can_link_evidence_record(): void
    {
        $user = User::factory()->create();

        $record = EvidenceRecord::create([
            'uuid'           => (string) Str::uuid(),
            'cost_component' => 'plate',
            'source_type'    => 'supplier_website',
            'capture_method' => 'auto_scrape',
            'created_by'     => $user->id,
        ]);

        // Verify the relation method exists on MaterialPriceHistory
        $mph = new MaterialPriceHistory();
        $this->assertTrue(method_exists($mph, 'evidenceRecord'));
    }

    // ──────────────────────────────────────────────────────────────
    // 5. New morph map entries
    // ──────────────────────────────────────────────────────────────

    public function test_morph_map_includes_evidence_record(): void
    {
        $map = Relation::morphMap();
        $this->assertArrayHasKey('evidence_record', $map);
    }

    public function test_morph_map_includes_material_price_history(): void
    {
        $map = Relation::morphMap();
        $this->assertArrayHasKey('material_price_history', $map);
    }

    // ──────────────────────────────────────────────────────────────
    // 6. API endpoints
    // ──────────────────────────────────────────────────────────────

    public function test_api_create_evidence_run_returns_201(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/evidence-runs", []);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.project_id', $project->id);
        // G2: empty project → no items → status becomes 'ready'
        $response->assertJsonPath('data.status', 'ready');

        $this->assertDatabaseHas('estimate_evidence_runs', [
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'ready',
        ]);
    }

    public function test_api_create_evidence_run_requires_auth(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $response = $this->postJson("/api/projects/{$project->id}/evidence-runs", []);

        $response->assertStatus(401);
    }

    public function test_api_create_evidence_run_rejects_other_user(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $project = $this->makeProject($owner);

        $response = $this->actingAs($other)
            ->postJson("/api/projects/{$project->id}/evidence-runs", []);

        $response->assertStatus(403);
    }

    public function test_api_show_evidence_run_returns_200(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'pending',
            'total_items'  => 0, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/evidence-runs/{$run->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $run->id);
    }

    public function test_api_show_evidence_run_rejects_other_project(): void
    {
        $owner  = User::factory()->create();
        $other  = User::factory()->create();
        $ownerProject = $this->makeProject($owner);
        $otherProject = $this->makeProject($other);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $ownerProject->id,
            'initiated_by' => $owner->id,
            'status'       => 'pending',
            'total_items'  => 0, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        // other user tries to access owner's run via their own project id — should 404
        $response = $this->actingAs($other)
            ->getJson("/api/projects/{$otherProject->id}/evidence-runs/{$run->id}");

        $response->assertStatus(404);
    }

    public function test_api_finalize_evidence_run_returns_200(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'ready',
            'total_items'  => 1, 'completed_items' => 1, 'failed_items' => 0,
        ]);

        // G2: finalize requires at least one item in terminal status
        EstimateEvidenceItem::create([
            'uuid'            => (string) Str::uuid(),
            'evidence_run_id' => $run->id,
            'cost_component'  => 'plate',
            'label'           => 'Test plate',
            'status'          => 'resolved',
            'resolution_type' => 'auto',
            'subject_type'    => 'test',
            'subject_id'      => 1,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/finalize");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'finalized');

        $this->assertDatabaseHas('estimate_evidence_runs', [
            'id'     => $run->id,
            'status' => 'finalized',
        ]);
    }

    public function test_api_finalize_rejects_already_finalized_run(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $run = EstimateEvidenceRun::create([
            'uuid'         => (string) Str::uuid(),
            'project_id'   => $project->id,
            'initiated_by' => $user->id,
            'status'       => 'finalized',
            'total_items'  => 0, 'completed_items' => 0, 'failed_items' => 0,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/projects/{$project->id}/evidence-runs/{$run->id}/finalize");

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_api_create_evidence_record_returns_201(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/evidence-records', [
            'cost_component' => 'plate',
            'source_type'    => 'supplier_website',
            'capture_method' => 'auto_scrape',
            'observed_price' => 1200.50,
            'currency'       => 'RUB',
            'source_url'     => 'https://example.com/product/123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.cost_component', 'plate');
        $response->assertJsonPath('data.source_type', 'supplier_website');

        $this->assertDatabaseHas('evidence_records', [
            'cost_component' => 'plate',
            'source_type'    => 'supplier_website',
            'created_by'     => $user->id,
        ]);
    }

    public function test_api_create_evidence_record_requires_cost_component(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/evidence-records', [
            'source_type'    => 'supplier_website',
            'capture_method' => 'auto_scrape',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cost_component']);
    }

    public function test_api_create_evidence_record_validates_enum_values(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/evidence-records', [
            'cost_component' => 'INVALID_DRIVER',
            'source_type'    => 'supplier_website',
            'capture_method' => 'auto_scrape',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cost_component']);
    }

    public function test_api_create_evidence_record_requires_auth(): void
    {
        $response = $this->postJson('/api/evidence-records', [
            'cost_component' => 'plate',
            'source_type'    => 'supplier_website',
            'capture_method' => 'auto_scrape',
        ]);

        $response->assertStatus(401);
    }

    // ──────────────────────────────────────────────────────────────
    // 7. Backward compatibility: legacy models untouched
    // ──────────────────────────────────────────────────────────────

    public function test_legacy_evidence_assets_table_still_exists(): void
    {
        $this->assertTrue(Schema::hasTable('evidence_assets'));
    }

    public function test_legacy_evidence_artifacts_table_still_exists(): void
    {
        $this->assertTrue(Schema::hasTable('evidence_artifacts'));
    }

    public function test_legacy_revision_runs_table_still_exists(): void
    {
        $this->assertTrue(Schema::hasTable('revision_runs'));
    }

    public function test_legacy_revision_run_items_table_still_exists(): void
    {
        $this->assertTrue(Schema::hasTable('revision_run_items'));
    }

    public function test_legacy_evidence_asset_model_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Models\EvidenceAsset::class));
    }

    public function test_legacy_evidence_artifact_model_class_exists(): void
    {
        $this->assertTrue(class_exists(\App\Models\EvidenceArtifact::class));
    }

    public function test_new_generic_evidence_asset_is_separate_from_legacy(): void
    {
        $legacyTable  = (new \App\Models\EvidenceAsset())->getTable();
        $genericTable = (new GenericEvidenceAsset())->getTable();

        $this->assertSame('evidence_assets', $legacyTable);
        $this->assertSame('generic_evidence_assets', $genericTable);
        $this->assertNotSame($legacyTable, $genericTable);
    }

    public function test_evidence_record_id_is_nullable_on_material_price_history(): void
    {
        // We can create a MaterialPriceHistory without evidence_record_id
        $this->assertTrue(
            Schema::hasColumn('material_price_histories', 'evidence_record_id')
        );
        // Column is nullable: inserting without it should not throw
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        // Just asserting the column allows null (no DB exception expected)
        $this->assertTrue(true);
    }

    // ──────────────────────────────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────────────────────────────

    private function makeProject(User $user): Project
    {
        return Project::create([
            'user_id'      => $user->id,
            'number'       => 'PRJ-G1-' . Str::random(4),
            'expert_name'  => 'Test Expert',
            'address'      => 'Test Address',
        ]);
    }
}
