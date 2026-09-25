<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\Expert\ExpertMaterialIdentity;
use App\Models\Expert\ExpertStorageMigration;
use App\Models\User;
use App\Services\Expert\ExpertMaterialService;
use App\Services\Expert\ExpertStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Closure;
use Tests\TestCase;

final class ExpertStorageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_reports_user_totals(): void
    {
        $this->configureStorage();
        [$user, $project, $material] = $this->material('migration dry run content');

        $this->artisan('expert:storage-migrate', ['--all' => true, '--dry-run' => true])
            ->expectsOutputToContain('Dry-run: записи журнала и объекты S1 не изменялись.')
            ->expectsOutputToContain('Legacy local: 1')
            ->expectsOutputToContain('User '.$user->id.' | files: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('expert_storage_migrations', 0);
        $this->assertSame([], Storage::disk('s1')->allFiles());
        $this->assertSame('local', $material->fresh()->storageDisk());
    }

    public function test_verified_stream_copy_switches_pointer_and_preserves_usage_and_source(): void
    {
        $this->configureStorage();
        [, , $material] = $this->material(str_repeat('safe migration bytes ', 32));
        $this->seedUsage($material);
        $before = DB::table('expert_storage_usages')->where('user_id', $material->project->user_id)->first();

        $this->runMigration($material);

        $fresh = $material->fresh();
        $journal = ExpertStorageMigration::query()->where('material_id', $material->id)->firstOrFail();
        $this->assertSame('s1', $fresh->storageDisk());
        $this->assertSame($this->targetKey($material), $fresh->storageKey());
        $this->assertSame(str_repeat('safe migration bytes ', 32), Storage::disk('s1')->get($fresh->storageKey()));
        Storage::disk('local')->assertExists($journal->source_key);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($journal->source_key)), $journal->source_sha256);
        $this->assertSame($journal->source_sha256, $journal->target_sha256);
        $this->assertSame(ExpertStorageMigration::STATUS_CLEANUP_PENDING, $journal->status);
        $this->assertSame((int) $material->size, $fresh->size);
        $this->assertEquals($before, DB::table('expert_storage_usages')->where('user_id', $material->project->user_id)->first());
    }

    public function test_matching_existing_target_is_reused_and_mismatched_target_is_not_overwritten(): void
    {
        $this->configureStorage();
        [, , $matching] = $this->material('same target bytes');
        Storage::disk('s1')->put($this->targetKey($matching), 'same target bytes');
        $this->runMigration($matching);
        $this->assertSame('s1', $matching->fresh()->storageDisk());
        $this->assertDatabaseHas('expert_storage_migrations', [
            'material_id' => $matching->id,
            'target_created' => false,
            'status' => ExpertStorageMigration::STATUS_CLEANUP_PENDING,
        ]);

        [, , $conflicting] = $this->material('source bytes');
        Storage::disk('s1')->put($this->targetKey($conflicting), 'different target bytes');
        $this->artisan('expert:storage-migrate', ['--material' => $conflicting->public_id])->assertExitCode(1);

        $this->assertSame('local', $conflicting->fresh()->storageDisk());
        $this->assertSame('different target bytes', Storage::disk('s1')->get($this->targetKey($conflicting)));
        $this->assertDatabaseHas('expert_storage_migrations', [
            'material_id' => $conflicting->id,
            'status' => ExpertStorageMigration::STATUS_FAILED,
            'last_error_code' => 'TARGET_CONFLICT',
        ]);
    }

    public function test_resume_uses_a_matching_target_after_a_failed_attempt_without_another_copy(): void
    {
        $this->configureStorage();
        [, , $material] = $this->material('recover this migration');
        $target = $this->targetKey($material);
        $hash = hash('sha256', 'recover this migration');
        Storage::disk('s1')->put($target, 'recover this migration');
        $journal = ExpertStorageMigration::query()->create([
            'material_id' => $material->id,
            'source_disk' => 'local',
            'source_key' => $material->storage_path,
            'target_disk' => 's1',
            'target_key' => $target,
            'source_size' => $material->size,
            'source_sha256' => $hash,
            'target_created' => true,
            'status' => ExpertStorageMigration::STATUS_FAILED,
            'attempts' => 1,
            'last_error_code' => 'WRITE_FAILED',
        ]);

        $this->artisan('expert:storage-migrate', ['--material' => $material->public_id, '--resume' => true])
            ->assertSuccessful();

        $this->assertSame('s1', $material->fresh()->storageDisk());
        $this->assertSame(2, $journal->fresh()->attempts);
        $this->assertSame(ExpertStorageMigration::STATUS_CLEANUP_PENDING, $journal->fresh()->status);
        $this->assertSame('recover this migration', Storage::disk('s1')->get($target));

        $updatedAt = $journal->fresh()->updated_at->timestamp;
        $this->travel(2)->seconds();
        $this->artisan('expert:storage-migrate', ['--material' => $material->public_id, '--resume' => true])->assertSuccessful();
        $this->assertSame(2, $journal->fresh()->attempts);
        $this->assertSame($updatedAt, $journal->fresh()->updated_at->timestamp);
    }

    public function test_trusted_identity_hash_is_reused_and_stale_identity_hash_is_ignored(): void
    {
        $this->configureStorage();
        [, , $trusted] = $this->material('trusted identity bytes');
        $trusted->identity()->create([
            'schema_version' => 'v1',
            'state' => 'content_enriched',
            'source_sha256' => hash('sha256', 'trusted identity bytes'),
            'metadata_hash' => hash('sha256', 'metadata'),
            'descriptor' => [],
        ]);
        [, , $stale] = $this->material('new current bytes');
        $stale->identity()->create([
            'schema_version' => 'v1',
            'state' => 'stale',
            'source_sha256' => hash('sha256', 'old bytes'),
            'metadata_hash' => hash('sha256', 'metadata'),
            'descriptor' => [],
        ]);

        $plan = app(\App\Services\Expert\ExpertStorageMigrationService::class)->plan([]);
        $this->assertSame(1, $plan['summary']['hash_missing']);
        $this->runMigration($trusted);
        $this->runMigration($stale);

        $this->assertSame(hash('sha256', 'trusted identity bytes'), ExpertStorageMigration::where('material_id', $trusted->id)->value('source_sha256'));
        $this->assertSame(hash('sha256', 'new current bytes'), ExpertStorageMigration::where('material_id', $stale->id)->value('source_sha256'));
        $this->assertSame('s1', $trusted->fresh()->storageDisk());
        $this->assertSame('s1', $stale->fresh()->storageDisk());
    }

    public function test_size_mismatch_and_missing_source_are_journaled_without_switching(): void
    {
        $this->configureStorage();
        [, , $missing] = $this->material('missing source');
        Storage::disk('local')->delete($missing->storage_path);
        $this->artisan('expert:storage-migrate', ['--material' => $missing->public_id])->assertExitCode(1);
        $this->assertSame('local', $missing->fresh()->storageDisk());
        $this->assertDatabaseHas('expert_storage_migrations', [
            'material_id' => $missing->id,
            'last_error_code' => 'SOURCE_MISSING',
        ]);

        [, , $mismatch] = $this->material('physical bytes');
        $mismatch->forceFill(['size' => 999])->save();
        $this->artisan('expert:storage-migrate', ['--material' => $mismatch->public_id])->assertExitCode(1);
        $this->assertSame('local', $mismatch->fresh()->storageDisk());
        $this->assertDatabaseHas('expert_storage_migrations', [
            'material_id' => $mismatch->id,
            'last_error_code' => 'SOURCE_SIZE_MISMATCH',
        ]);
        $this->assertSame([], Storage::disk('s1')->allFiles('expert'));
    }

    public function test_bad_target_hash_keeps_material_local_and_journals_failure(): void
    {
        $this->configureStorage();
        [, , $material] = $this->material('source content for hash verification');
        $corruptBytes = str_repeat('x', strlen('source content for hash verification'));
        $this->interceptS1StreamWrites(function (string $key) use ($corruptBytes): void {
            Storage::disk('s1')->put($key, $corruptBytes);
        });

        $result = app(\App\Services\Expert\ExpertStorageMigrationService::class)
            ->migrate(['material_id' => $material->id], false, 50);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['copied']);
        $this->assertSame(0, $result['verified']);
        $this->assertSame('local', $material->fresh()->storageDisk());
        $this->assertDatabaseHas('expert_storage_migrations', [
            'material_id' => $material->id,
            'status' => ExpertStorageMigration::STATUS_FAILED,
            'last_error_code' => 'TARGET_SHA256_MISMATCH',
        ]);
    }

    public function test_database_switch_failure_keeps_local_pointer_and_verified_target_journaled(): void
    {
        $this->configureStorage();
        [, , $material] = $this->material('db switch failure');
        $eventName = 'eloquent.updating: '.ExpertProjectMaterial::class;
        Event::listen($eventName, function (ExpertProjectMaterial $updating): void {
            if ($updating->storage_disk === 's1') {
                throw new \RuntimeException('Injected switch failure.');
            }
        });

        try {
            $this->artisan('expert:storage-migrate', ['--material' => $material->public_id])->assertExitCode(1);
        } finally {
            Event::forget($eventName);
        }

        $journal = ExpertStorageMigration::query()->where('material_id', $material->id)->firstOrFail();
        $this->assertSame('local', $material->fresh()->storageDisk());
        $this->assertSame(ExpertStorageMigration::STATUS_FAILED, $journal->status);
        $this->assertSame('DB_SWITCH_FAILED', $journal->last_error_code);
        $this->assertSame(hash('sha256', 'db switch failure'), hash('sha256', Storage::disk('s1')->get($journal->target_key)));
    }

    public function test_material_deleted_during_copy_is_not_resurrected_and_target_cleanup_is_scheduled(): void
    {
        $this->configureStorage();
        [, , $material] = $this->material('deleted while copying');
        $materialId = (int) $material->id;
        $materialService = app(ExpertMaterialService::class);
        $this->interceptS1StreamWrites(function () use ($material, $materialService): void {
            $materialService->delete($material);
        });

        $result = app(\App\Services\Expert\ExpertStorageMigrationService::class)
            ->migrate(['material_id' => $materialId], false, 50);

        $journal = ExpertStorageMigration::query()->whereNull('material_id')->firstOrFail();
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseMissing('expert_project_materials', ['id' => $materialId]);
        $this->assertSame('MATERIAL_DELETED', $journal->last_error_code);
        $this->assertDatabaseHas('expert_storage_cleanup_tasks', ['disk' => 's1', 'path' => $journal->target_key]);
        Storage::disk('s1')->assertExists($journal->target_key);
    }

    public function test_single_material_rollback_keeps_s1_object_and_cleanup_waits_for_grace_period(): void
    {
        $this->configureStorage();
        config()->set('expert.storage_migration.grace_days', 7);
        [, , $material] = $this->material('rollback source remains');
        $this->seedUsage($material);
        $this->runMigration($material);
        $target = $this->targetKey($material);

        $this->artisan('expert:storage-migration-rollback', ['--material' => $material->public_id])->assertSuccessful();
        $this->assertSame('local', $material->fresh()->storageDisk());
        Storage::disk('local')->assertExists($material->storage_path);
        Storage::disk('s1')->assertExists($target);

        $this->runMigration($material);
        $this->travel(8)->days();
        $this->artisan('expert:storage-migration-cleanup')->assertSuccessful();
        Storage::disk('local')->assertMissing($material->storage_path);
        Storage::disk('s1')->assertExists($target);
        $this->assertDatabaseHas('expert_storage_migrations', [
            'material_id' => $material->id,
            'status' => ExpertStorageMigration::STATUS_COMPLETED,
        ]);
    }

    public function test_all_write_run_needs_explicit_confirmation_and_audit_checks_physical_size(): void
    {
        $this->configureStorage();
        config()->set('expert.storage_migration.enabled', true);
        [, , $material] = $this->material('physical object');
        $this->artisan('expert:storage-migrate', ['--all' => true])
            ->expectsOutputToContain('--all и --confirm-all')
            ->assertExitCode(1);

        $material->forceFill(['size' => 200])->save();
        $this->artisan('expert:storage-audit', ['--material' => $material->public_id])
            ->expectsOutputToContain('SIZE_MISMATCH')
            ->assertExitCode(1);
        $this->assertSame('local', $material->fresh()->storageDisk());
    }

    private function configureStorage(): void
    {
        Storage::fake('local');
        config()->set('expert.storage_migration.enabled', true);
        config()->set('expert.storage_migration.grace_days', null);
        config()->set('filesystems.disks.s1', [
            'driver' => 's3',
            'key' => 'test-access-key',
            'secret' => 'test-secret',
            'region' => 'test-region',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://s1.test.invalid',
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'stream_reads' => true,
            'throw' => true,
            'report' => false,
        ]);
        Storage::fake('s1');
    }

    /** @return array{User, ExpertProject, ExpertProjectMaterial} */
    private function material(string $contents): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'Storage migration test',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $key = "expert/{$project->public_id}/materials/source.txt";
        Storage::disk('local')->put($key, $contents);
        $material = $project->materials()->create([
            'uploaded_by' => $user->id,
            'original_name' => 'source.txt',
            'storage_path' => $key,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => strlen($contents),
            'category' => 'document',
            'status' => 'uploaded',
        ]);

        return [$user, $project, $material];
    }

    private function runMigration(ExpertProjectMaterial $material): void
    {
        $this->artisan('expert:storage-migrate', ['--material' => $material->public_id])->assertSuccessful();
    }

    private function seedUsage(ExpertProjectMaterial $material): void
    {
        DB::table('expert_storage_usages')->insert([
            'user_id' => $material->project->user_id,
            'originals_bytes' => $material->size,
            'materials_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function targetKey(ExpertProjectMaterial $material): string
    {
        return "expert/{$material->project->public_id}/materials/{$material->public_id}.txt";
    }

    private function interceptS1StreamWrites(Closure $callback): void
    {
        $root = storage_path('framework/testing/disks/s1');
        $adapter = new CallbackLocalFilesystemAdapter($root, $callback);
        $driver = new Filesystem($adapter);
        Storage::set('s1', new LaravelFilesystemAdapter($driver, $adapter, config('filesystems.disks.s1')));
    }
}

final class CallbackLocalFilesystemAdapter extends LocalFilesystemAdapter
{
    public function __construct(string $location, private readonly Closure $afterStreamWrite)
    {
        parent::__construct($location);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        parent::writeStream($path, $contents, $config);
        ($this->afterStreamWrite)($path, $contents);
    }
}
