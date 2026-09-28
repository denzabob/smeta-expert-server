<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\EvidenceRecord;
use App\Models\GenericEvidenceAsset;
use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\Storage\AccountFileStorage;
use App\Services\Storage\ObjectStorage;
use App\Services\Storage\ObjectStorageException;
use App\Services\Storage\StorageUsageService;
use App\Services\LaborEvidenceAssetService;
use App\Jobs\DeleteAccountStorageFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class AccountStorageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private BillingPlan $plan;
    private User $user;
    private ExpertProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.s1' => ['driver' => 's3', 'key' => 'fixture', 'secret' => 'fixture',
            'region' => 'us-east-1', 'bucket' => 'fixture', 'endpoint' => 'https://s1.invalid',
            'visibility' => 'private', 'stream_reads' => true, 'throw' => true],
            'expert.storage.disk' => 's1', 'billing.default_plan' => 'account-fixture']);
        Storage::fake('s1');
        Storage::fake('local');
        Storage::fake('public');
        $this->plan = BillingPlan::create(['code' => 'account-fixture', 'name' => 'Account fixture', 'is_active' => true,
            'metadata_json' => ['limits' => ['storage_bytes' => 20]]]);
        $this->user = User::factory()->create();
        $this->project = ExpertProject::create(['user_id' => $this->user->id, 'name' => 'Storage', 'domain' => 'other', 'work_type' => 'other']);
    }

    public function test_real_expert_and_smeta_flows_share_quota_and_account_api(): void
    {
        $expert = $this->actingAs($this->user, 'sanctum')->post('/api/expert/projects/'.$this->project->public_id.'/materials',
            ['file' => UploadedFile::fake()->createWithContent('expert.txt', '12345678')], ['Accept' => 'application/json'])->assertCreated();
        $record = $this->record();
        $this->uploadEvidence($record, '1234567')->assertCreated();
        $this->uploadEvidence($record, '1234')->assertCreated();
        $this->actingAs($this->user, 'sanctum')->post('/api/expert/projects/'.$this->project->public_id.'/materials',
            ['file' => UploadedFile::fake()->createWithContent('blocked.txt', '12')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED')->assertJsonPath('used_bytes', 19)
            ->assertJsonPath('reserved_bytes', 0)->assertJsonPath('available_bytes', 1);
        $this->getJson('/api/account/storage')->assertOk()->assertJsonPath('used_bytes', 19)
            ->assertJsonPath('reserved_bytes', 0)->assertJsonPath('files_count', 3)
            ->assertJsonPath('modules.expert.bytes', 8)->assertJsonPath('modules.smeta.bytes', 11)
            ->assertJsonPath('categories.files.bytes', 19)->assertJsonMissingPath('disk')->assertJsonMissingPath('path');
        $this->assertDatabaseCount('expert_storage_usages', 0);
        $this->assertDatabaseCount('expert_storage_upload_reservations', 0);
        $this->get('/api/expert/materials/'.$expert->json('public_id').'/download')->assertOk();
        $asset = $record->assets()->firstOrFail();
        $download = $this->get('/api/generic-evidence-assets/'.$asset->id.'/file')->assertOk();
        $this->assertSame('1234567', $download->streamedContent());
        $this->assertSame([], Storage::disk('local')->allFiles('smeta'));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_shared_evidence_last_link_delete_and_downgrade_keep_download_available(): void
    {
        $record = $this->record();
        $this->uploadEvidence($record, '1234567')->assertCreated();
        $asset = $record->assets()->firstOrFail();
        $copy = GenericEvidenceAsset::create(['uuid' => (string) Str::uuid(), 'evidence_record_id' => $record->id,
            'asset_type' => 'document', 'storage_disk' => 's1', 'file_path' => $asset->file_path, 'file_size' => 7]);
        $this->assertSame(7, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
        app(LaborEvidenceAssetService::class)->delete($asset);
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertExists($copy->file_path);
        $this->plan->update(['metadata_json' => ['limits' => ['storage_bytes' => 0]]]);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/account/storage')->assertJsonPath('over_quota', true);
        $this->uploadEvidence($record, 'x')->assertStatus(422)->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED');
        $this->get('/api/generic-evidence-assets/'.$copy->id.'/file')->assertOk();
        app(LaborEvidenceAssetService::class)->delete($copy);
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertMissing($copy->file_path);
        $snapshot = app(StorageUsageService::class)->getUserUsage($this->user->id);
        $this->assertSame(0, $snapshot['used_bytes']);
        $this->assertSame(0, $snapshot['files_count']);
        $this->assertSame([], app(StorageUsageService::class)->audit($this->user->id)['mismatches']);
    }

    public function test_database_finalize_failure_compensates_s1_and_releases_reservation(): void
    {
        try {
            app(AccountFileStorage::class)->upload('evidence-records', UploadedFile::fake()->createWithContent('broken.txt', '123'),
                $this->user->id, fn () => throw new RuntimeException('fixture DB failure'));
            $this->fail('Expected failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('fixture DB failure', $e->getMessage());
        }
        $this->assertDatabaseCount('storage_files', 0);
        $this->assertDatabaseHas('storage_upload_reservations', ['user_id' => $this->user->id, 'status' => 'released']);
        $this->assertSame([], Storage::disk('s1')->allFiles('smeta'));
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['reserved_bytes']);
    }

    public function test_partial_s1_write_failure_is_compensated_without_local_fallback(): void
    {
        $path = 'smeta/evidence-records/partial.txt';
        $objects = $this->mock(ObjectStorage::class);
        $objects->shouldReceive('storeUploaded')->once()->andReturnUsing(function () use ($path) {
            Storage::disk('s1')->put($path, 'partial');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, objectDisk: 's1', objectPath: $path);
        });
        $objects->shouldReceive('delete')->once()->with('s1', $path)->andReturnUsing(fn () => Storage::disk('s1')->delete($path));
        try {
            app(AccountFileStorage::class)->prepareUploaded('evidence-records', UploadedFile::fake()->createWithContent('broken.txt', '123'), $this->user->id);
            $this->fail('Expected S1 failure.');
        } catch (ObjectStorageException $e) {
            $this->assertSame('STORAGE_BACKEND_UNAVAILABLE', $e->failureCode);
        }
        $this->assertDatabaseCount('storage_files', 0);
        $this->assertSame([], Storage::disk('s1')->allFiles('smeta'));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['reserved_bytes']);
    }

    public function test_owner_conflict_rolls_back_business_row_and_nonbillable_processing_has_no_reservation(): void
    {
        $other = User::factory()->create();
        $record = $this->record();
        $otherRecord = EvidenceRecord::create(['uuid' => (string) Str::uuid(), 'cost_component' => 'operation',
            'source_type' => 'document', 'capture_method' => 'file_upload', 'verification_status' => 'pending', 'created_by' => $other->id]);
        $this->uploadEvidence($record, 'abc')->assertCreated();
        $asset = $record->assets()->firstOrFail();
        try {
            GenericEvidenceAsset::create(['uuid' => (string) Str::uuid(), 'evidence_record_id' => $otherRecord->id,
                'asset_type' => 'document', 'file_path' => $asset->file_path, 'storage_disk' => 's1']);
            $this->fail('Cross-account links must fail.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('two accounts', $e->getMessage());
        }
        $this->assertDatabaseCount('generic_evidence_assets', 1);
        $beforeReservations = DB::table('storage_upload_reservations')->count();
        $this->plan->update(['metadata_json' => ['limits' => ['storage_bytes' => 0]]]);
        $key = app(ObjectStorage::class)->storeUploaded('imports', UploadedFile::fake()->createWithContent('temp.csv', 'temporary'), $this->user->id);
        Storage::disk('s1')->assertExists($key);
        $this->assertSame($beforeReservations, DB::table('storage_upload_reservations')->count());
        $this->assertSame(3, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
    }

    public function test_uuid_revision_snapshot_keeps_shared_evidence_until_project_cascade_delete(): void
    {
        $record = $this->record();
        $this->uploadEvidence($record, 'abc')->assertCreated();
        $asset = $record->assets()->firstOrFail();
        $project = Project::create(['user_id' => $this->user->id, 'number' => 'ACCOUNT-STORAGE',
            'expert_name' => 'Fixture', 'address' => 'Fixture']);
        $snapshot = json_encode(['evidence' => [['screenshot_path' => $asset->file_path, 'storage_disk' => 's1']]], JSON_THROW_ON_ERROR);
        $revision = ProjectRevision::create(['project_id' => $project->id, 'number' => 1, 'status' => 'locked',
            'snapshot_json' => $snapshot, 'snapshot_hash' => hash('sha256', $snapshot)]);
        $this->assertDatabaseHas('storage_file_links', ['source_type' => 'project_revisions', 'source_id' => $revision->id]);
        $record->delete(); // FK cascades assets without their Eloquent delete events.
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertExists($asset->file_path);
        $this->assertSame(3, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
        $this->assertDatabaseMissing('storage_file_links', ['source_type' => 'generic_evidence_assets', 'source_id' => $asset->id]);
        $project->delete();
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertMissing($asset->file_path);
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
    }

    public function test_backfill_is_atomic_idempotent_and_excludes_shared_platform_parser_screenshots(): void
    {
        $record = $this->record();
        $key = 'smeta/evidence-records/existing.txt';
        Storage::disk('s1')->put($key, 'abc');
        $asset = GenericEvidenceAsset::create(['uuid' => (string) Str::uuid(), 'evidence_record_id' => $record->id,
            'asset_type' => 'document', 'storage_disk' => 's1', 'file_path' => $key, 'file_size' => 3]);
        $parser = 'smeta/screenshots/parser/system.png';
        foreach ([$this->user, User::factory()->create()] as $owner) {
            $project = Project::create(['user_id' => $owner->id, 'number' => 'PARSER-'.$owner->id,
                'expert_name' => 'Fixture', 'address' => 'Fixture']);
            $json = json_encode(['screenshot_path' => $parser, 'storage_disk' => 's1']);
            ProjectRevision::create(['project_id' => $project->id, 'number' => 1, 'status' => 'locked',
                'snapshot_json' => $json, 'snapshot_hash' => hash('sha256', $json)]);
        }
        $this->artisan('storage:backfill-registry', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('storage_files', 0);
        $asset->update(['file_size' => 4]);
        $this->artisan('storage:backfill-registry')->expectsOutputToContain('STORAGE_OBJECT_METADATA_CONFLICT')->assertFailed();
        $this->assertDatabaseCount('storage_files', 0);
        $asset->update(['file_size' => 3]);
        $this->artisan('storage:backfill-registry')->assertSuccessful();
        $this->artisan('storage:backfill-registry')->assertSuccessful();
        $this->assertDatabaseCount('storage_files', 1);
        $this->assertDatabaseCount('storage_file_links', 1);
        $this->assertSame(3, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
        $this->artisan('storage:usage-audit')->expectsOutputToContain('Used mismatches: 0')->assertSuccessful();
    }

    public function test_actual_size_exceeding_limit_compensates_before_business_creation(): void
    {
        $objects = $this->partialMock(ObjectStorage::class);
        $objects->shouldReceive('size')->once()->andReturn(21);
        try {
            app(AccountFileStorage::class)->upload('evidence-records', UploadedFile::fake()->createWithContent('actual.txt', 'abc'),
                $this->user->id, fn () => $this->fail('Business write must not run when actual size exceeds quota.'));
            $this->fail('Expected actual-size quota failure.');
        } catch (\App\Services\Storage\StorageQuotaException $e) {
            $this->assertSame('STORAGE_QUOTA_EXCEEDED', $e->errorCode);
        }
        $this->assertDatabaseCount('storage_files', 0);
        $this->assertSame([], Storage::disk('s1')->allFiles('smeta'));
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['reserved_bytes']);
    }

    public function test_delete_retry_keeps_durable_deleting_record_without_billable_usage(): void
    {
        $record = $this->record();
        $this->uploadEvidence($record, 'abc')->assertCreated();
        $asset = $record->assets()->firstOrFail();
        $objects = $this->mock(ObjectStorage::class);
        $objects->shouldReceive('delete')->andThrow(new RuntimeException('fixture S1 unavailable'));
        $asset->delete();
        try {
            (new DeleteAccountStorageFiles())->handle($objects);
            $this->fail('The queue job must retry failures.');
        } catch (RuntimeException $e) {
            $this->assertSame('STORAGE_DELETE_FAILED', $e->getMessage());
        }
        $this->assertDatabaseHas('storage_files', ['path' => $asset->file_path, 'status' => 'deleting']);
        Storage::disk('s1')->assertExists($asset->file_path);
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
        $this->app->forgetInstance(ObjectStorage::class);
        (new DeleteAccountStorageFiles())->handle(new ObjectStorage());
        Storage::disk('s1')->assertMissing($asset->file_path);
        $this->assertDatabaseHas('storage_files', ['path' => $asset->file_path, 'status' => 'deleted']);
    }

    public function test_price_document_and_finished_product_screenshot_use_shared_registry_and_protected_downloads(): void
    {
        $this->plan->update(['metadata_json' => ['limits' => ['storage_bytes' => null]]]);
        $supplier = \App\Models\Supplier::create(['user_id' => $this->user->id, 'name' => 'Account supplier', 'code' => 'ACCOUNT']);
        $csv = "name,price\nFixture,100\n";
        $document = $this->actingAs($this->user, 'sanctum')->post('/api/suppliers/'.$supplier->id.'/price-documents',
            ['source_type' => 'file', 'file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)],
            ['Accept' => 'application/json'])->assertCreated();
        $version = \App\Models\PriceListVersion::findOrFail($document->json('version.id'));
        $response = $this->get('/api/price-list-versions/'.$version->id.'/download')->assertOk();
        $this->assertSame($csv, $response->streamedContent());
        $specification = \App\Models\FinishedProductSpecification::create(['user_id' => $this->user->id, 'product_type' => 'facade', 'name' => 'Fixture']);
        $source = \App\Models\FinishedProductPriceSource::create(['finished_product_specification_id' => $specification->id,
            'source_kind' => 'manual_entry', 'source_price' => 100, 'source_unit' => 'm2', 'conversion_factor_to_m2' => 1,
            'price_per_m2_normalized' => 100, 'captured_at' => now(), 'status' => 'active']);
        $image = UploadedFile::fake()->image('screenshot.png', 16, 16);
        $assetResponse = $this->post('/api/finished-product-price-sources/'.$source->id.'/evidence-assets',
            ['asset_type' => 'screenshot', 'file' => $image], ['Accept' => 'application/json'])->assertCreated();
        $asset = \App\Models\FinishedProductPriceEvidenceAsset::where('finished_product_price_source_id', $source->id)->firstOrFail();
        $this->get('/api/finished-product-price-evidence-assets/'.$asset->id.'/open')->assertOk();
        $usage = app(StorageUsageService::class)->getUserUsage($this->user->id);
        $this->assertSame(strlen($csv) + (int) $asset->file_size, $usage['used_bytes']);
        $this->assertSame(1, $usage['images_count']);
        $this->assertSame((int) $asset->file_size, $usage['images_bytes']);
        $this->assertDatabaseHas('storage_files', ['path' => $version->file_path, 'module' => 'smeta', 'category' => 'file']);
        $this->assertDatabaseHas('storage_files', ['path' => $asset->file_path, 'category' => 'image']);
        $this->deleteJson('/api/finished-product-price-evidence-assets/'.$asset->id)->assertNoContent();
        $supplier->delete();
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
        Storage::disk('s1')->assertMissing($asset->file_path);
        Storage::disk('s1')->assertMissing($version->file_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_hard_deleted_account_keeps_only_durable_physical_cleanup_tombstones(): void
    {
        $record = $this->record();
        $this->uploadEvidence($record, 'abc')->assertCreated();
        $asset = $record->assets()->firstOrFail();
        $admin = User::factory()->create();
        $objects = $this->partialMock(ObjectStorage::class);
        $objects->shouldReceive('delete')->andThrow(new RuntimeException('fixture S1 unavailable'));
        app(\App\Services\Admin\AdminUserService::class)->hardDeleteUser($this->user, $admin);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('storage_files', ['path' => $asset->file_path, 'user_id' => null, 'status' => 'deleting']);
        $this->assertDatabaseCount('storage_file_links', 0);
        $this->assertDatabaseCount('storage_upload_reservations', 0);
        $this->app->forgetInstance(ObjectStorage::class);
        $this->artisan('smeta:storage-reset', ['--confirm' => true])->assertSuccessful();
        Storage::disk('s1')->assertMissing($asset->file_path);
        $this->assertDatabaseHas('storage_files', ['path' => $asset->file_path, 'user_id' => null, 'status' => 'deleted']);
    }

    public function test_retained_price_import_original_is_parsed_from_s1_and_reuse_is_counted_once(): void
    {
        $this->plan->update(['metadata_json' => ['limits' => ['storage_bytes' => null]]]);
        $supplier = \App\Models\Supplier::create(['user_id' => $this->user->id, 'name' => 'Parser supplier', 'code' => 'PARSER']);
        $list = \App\Models\PriceList::create(['supplier_id' => $supplier->id, 'name' => 'Parser list', 'type' => 'materials']);
        $csv = "name,price\nFixture,100\n";
        $service = app(\App\Services\PriceImport\PriceImportSessionService::class);
        $session = $service->createFromUpload(UploadedFile::fake()->createWithContent('prices.csv', $csv),
            $this->user, 'materials', $supplier->id, $list->id);
        $this->assertSame('s1', $session->storage_disk);
        $this->assertSame([['name', 'price'], ['Fixture', '100']], $session->raw_rows);
        Storage::disk('s1')->assertExists($session->file_path);
        $copy = $service->createFromExistingSession($session, $this->user, 'materials', $supplier->id, $list->id);
        $usage = app(StorageUsageService::class)->getUserUsage($this->user->id);
        $this->assertSame(strlen($csv), $usage['used_bytes']);
        $this->assertSame(1, $usage['files_count']);
        $this->assertDatabaseCount('storage_file_links', 2);
        $session->delete();
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertExists($copy->file_path);
        $copy->delete();
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertMissing($copy->file_path);
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
        $this->assertSame([], Storage::disk('local')->allFiles('smeta'));
    }

    public function test_backfill_preserves_expert_physical_module_after_original_material_reference_is_removed(): void
    {
        $material = app(\App\Services\Expert\ExpertMaterialService::class)->store($this->project, $this->user->id,
            UploadedFile::fake()->createWithContent('shared.txt', 'abc'));
        $record = $this->record();
        $asset = GenericEvidenceAsset::create(['uuid' => (string) Str::uuid(), 'evidence_record_id' => $record->id,
            'asset_type' => 'document', 'storage_disk' => 's1', 'file_path' => $material->storage_path, 'file_size' => 3]);
        app(\App\Services\Expert\ExpertMaterialService::class)->delete($material);
        $this->assertDatabaseHas('storage_file_links', ['module' => 'smeta', 'source_type' => 'generic_evidence_assets', 'source_id' => $asset->id]);
        $this->artisan('storage:backfill-registry')->assertSuccessful();
        $this->assertDatabaseHas('storage_files', ['module' => 'expert', 'path' => $asset->file_path, 'status' => 'active']);
        $this->assertSame(3, app(StorageUsageService::class)->getUserUsage($this->user->id)['modules']['expert']['used_bytes']);
        $asset->delete();
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        Storage::disk('s1')->assertMissing($asset->file_path);
        $this->assertSame(0, app(StorageUsageService::class)->getUserUsage($this->user->id)['used_bytes']);
    }

    private function record(): EvidenceRecord
    {
        return EvidenceRecord::create(['uuid' => (string) Str::uuid(), 'cost_component' => 'operation', 'source_type' => 'document',
            'capture_method' => 'file_upload', 'verification_status' => 'pending', 'created_by' => $this->user->id]);
    }

    private function uploadEvidence(EvidenceRecord $record, string $contents)
    {
        return $this->actingAs($this->user, 'sanctum')->post('/api/evidence-records/'.$record->id.'/assets',
            ['file' => UploadedFile::fake()->createWithContent('evidence.txt', $contents), 'asset_type' => 'document'],
            ['Accept' => 'application/json']);
    }
}
