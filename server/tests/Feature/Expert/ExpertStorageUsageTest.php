<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Models\BillingPlan;
use App\Jobs\DeleteAccountStorageFiles;
use App\Services\Storage\ObjectStorage;
use App\Services\Storage\StorageUsageService;
use Illuminate\Support\Facades\DB;
use App\Services\Expert\ExpertMaterialService;
use App\Services\Expert\ExpertProjectService;
use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExpertStorageUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureFakeS1();
        config(['expert.storage.disk' => 's1', 'expert.storage.cache_disk' => 'local']);
        Storage::fake('local');
        config(['billing.default_plan' => 'storage-usage-default']);
        BillingPlan::query()->create(['code' => 'storage-usage-default', 'name' => 'Usage test',
            'is_active' => true, 'metadata_json' => ['limits' => ['storage_bytes' => null]]]);
    }

    public function test_local_and_s1_originals_share_owner_usage_and_project_delete_subtracts_once(): void
    {
        [$user, $firstProject] = $this->project();
        [, $secondProject] = $this->project($user, 'Second project');

        $local = $this->material($firstProject, $user, 'legacy-local.txt', strlen('local-original'));
        Storage::disk('local')->put($local->storage_path, 'local-original');
        $remote = $this->upload($user, $firstProject, 'remote.txt', 's1-original-bytes');
        $otherProject = $this->upload($user, $secondProject, 'other-project.txt', 'other');

        $usage = app(ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame(strlen('local-original') + strlen('s1-original-bytes') + strlen('other'), $usage->usedBytes);
        $this->assertSame(3, $usage->materialsCount);
        $this->assertSame('local', $local->storageDisk());
        $this->assertSame('s1', $remote->storageDisk());
        $this->assertSame('s1', $otherProject->storageDisk());

        $otherUser = User::factory()->create();
        $this->assertSame(0, app(ExpertStorageUsageService::class)->getUserUsage($otherUser)->usedBytes);

        app(ExpertProjectService::class)->delete($firstProject);
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        $usage = app(ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame(strlen('other'), $usage->usedBytes);
        $this->assertSame(1, $usage->materialsCount);
        $this->assertDatabaseMissing('expert_project_materials', ['id' => $local->id]);
        $this->assertDatabaseMissing('expert_project_materials', ['id' => $remote->id]);

        app(ExpertProjectService::class)->delete($secondProject);
        (new DeleteAccountStorageFiles())->handle(app(ObjectStorage::class));
        $usage = app(ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame(0, $usage->usedBytes);
        $this->assertSame(0, $usage->materialsCount);
    }

    public function test_material_delete_decrements_once_even_when_original_cleanup_is_deferred(): void
    {
        [$user, $project] = $this->project();
        $material = $this->upload($user, $project, 'keep-for-cleanup.txt', 'original-bytes');
        $s1Disk = Storage::disk('s1');
        $localDisk = Storage::disk('local');

        $failingDisk = Mockery::mock();
        $failingDisk->shouldReceive('exists')->andReturn(true);
        $failingDisk->shouldReceive('delete')->andThrow(new RuntimeException('simulated backend failure'));
        $failingDisk->shouldReceive('deleteDirectory')->andReturn(true);
        Storage::shouldReceive('disk')->with('s1')->andReturn($failingDisk);
        Storage::shouldReceive('disk')->with('local')->andReturn($localDisk);

        app(ExpertMaterialService::class)->delete($material);

        $this->assertDatabaseMissing('expert_project_materials', ['id' => $material->id]);
        $this->assertDatabaseHas('expert_storage_cleanup_tasks', [
            'disk' => 's1',
            'path' => $material->storage_path,
            'kind' => 'file',
        ]);
        $usage = app(ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame(0, $usage->usedBytes);
        $this->assertSame(0, $usage->materialsCount);

        app(ExpertMaterialService::class)->delete($material);
        $usage = app(ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame(0, $usage->usedBytes);
        $this->assertSame(0, $usage->materialsCount);
        $this->assertTrue($s1Disk->exists($material->storage_path));
    }

    public function test_database_failure_after_object_upload_rolls_back_material_and_usage_then_compensates(): void
    {
        [$user, $project] = $this->project();
        $eventName = 'eloquent.created: '.ExpertProjectMaterial::class;
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('simulated failure after material insert');
        });

        try {
            try {
                app(ExpertMaterialService::class)->store(
                    $project,
                    $user->id,
                    UploadedFile::fake()->createWithContent('rollback.txt', 'upload-that-must-roll-back'),
                );
                $this->fail('Expected the material transaction to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('simulated failure after material insert', $exception->getMessage());
            }
        } finally {
            Event::forget($eventName);
        }

        $this->assertDatabaseCount('expert_project_materials', 0);
        $this->assertDatabaseHas('storage_usages', [
            'user_id' => $user->id,
            'used_bytes' => 0,
            'reserved_bytes' => 0,
            'files_count' => 0,
        ]);
        $this->assertDatabaseHas('storage_upload_reservations', [
            'user_id' => $user->id,
            'status' => 'released',
        ]);
        $this->assertSame([], Storage::disk('s1')->allFiles("expert/{$project->public_id}"));
    }

    public function test_reconciliation_dry_run_is_read_only_and_repair_uses_registry_sizes(): void
    {
        Storage::fake('local');
        [$firstUser, $firstProject] = $this->project();
        [$secondUser, $secondProject] = $this->project();
        $this->material($firstProject, $firstUser, 'legacy-a.txt', 120);
        $this->material($firstProject, $firstUser, 'legacy-b.txt', 30);
        $this->material($secondProject, $secondUser, 'legacy-c.txt', 75);

        $this->counter($firstUser->id, 100, 1);
        DB::table('storage_usages')->where('user_id', $secondUser->id)->delete();

        $this->artisan('expert:storage-reconcile', [
            '--user' => $firstUser->id,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('difference: +50 B')
            ->expectsOutputToContain('Projection не изменена.')
            ->assertSuccessful();

        $this->assertDatabaseHas('storage_usages', [
            'user_id' => $firstUser->id,
            'used_bytes' => 100,
            'files_count' => 1,
        ]);
        $this->assertDatabaseMissing('storage_usages', ['user_id' => $secondUser->id]);

        $this->artisan('expert:storage-reconcile', [
            '--all' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('difference: +75 B')
            ->expectsOutputToContain('Projection не изменена.')
            ->assertSuccessful();
        $this->assertDatabaseMissing('storage_usages', ['user_id' => $secondUser->id]);

        $this->artisan('expert:storage-reconcile', ['--user' => $firstUser->id])->assertSuccessful();
        $this->artisan('expert:storage-reconcile', ['--all' => true])->assertSuccessful();

        $this->assertDatabaseHas('storage_usages', [
            'user_id' => $firstUser->id,
            'used_bytes' => 150,
            'files_count' => 2,
        ]);
        $this->assertDatabaseHas('storage_usages', [
            'user_id' => $secondUser->id,
            'used_bytes' => 75,
            'files_count' => 1,
        ]);

        $usage = app(ExpertStorageUsageService::class)->getUserUsage($firstUser);
        $this->assertSame([
            'used_bytes' => 150,
            'reserved_bytes' => 0,
            'limit_bytes' => null,
            'remaining_bytes' => null,
            'usage_percent' => null,
            'materials_count' => 2,
        ], $usage->toArray());
    }

    public function test_registry_register_and_unlink_are_idempotent_without_accounting_underflow(): void
    {
        $user = User::factory()->create();
        $usage = app(StorageUsageService::class);
        foreach ([1 => 10, 2 => 20] as $id => $size) {
            $metadata = ['disk' => 's1', 'path' => "expert/idempotent-{$id}", 'purpose' => 'material', 'size_bytes' => $size];
            $links = [['source_type' => 'expert_project_materials', 'source_id' => $id]];
            $usage->register((int) $user->id, 'expert', $metadata, $links);
            $usage->register((int) $user->id, 'expert', $metadata, $links);
        }
        $this->assertDatabaseHas('storage_usages', [
            'user_id' => $user->id,
            'used_bytes' => 30,
            'files_count' => 2,
        ]);

        foreach ([1, 2] as $id) {
            $usage->unlink('expert', 'expert_project_materials', $id);
            $this->assertSame([], $usage->unlink('expert', 'expert_project_materials', $id));
        }
        $snapshot = $usage->getUserUsage((int) $user->id);
        $this->assertSame(0, $snapshot['used_bytes']);
        $this->assertSame(0, $snapshot['files_count']);
        $this->assertDatabaseHas('storage_usages', [
            'user_id' => $user->id,
            'used_bytes' => 0,
            'files_count' => 0,
        ]);
        $this->assertDatabaseCount('expert_storage_usages', 0);
    }

    private function configureFakeS1(): void
    {
        config()->set('filesystems.disks.s1', [
            'driver' => 's3',
            'key' => 'test-access-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'expert-test-bucket',
            'endpoint' => 'https://s1.test.invalid',
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'stream_reads' => true,
            'http' => ['connect_timeout' => 3, 'timeout' => 20],
            'retries' => 2,
            'throw' => true,
            'report' => false,
        ]);
        Storage::purge('s1');
        Storage::fake('s1');
    }

    /** @return array{User, ExpertProject} */
    private function project(?User $user = null, string $name = 'Storage usage'): array
    {
        $user ??= User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => $name,
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return [$user, $project];
    }

    private function upload(User $user, ExpertProject $project, string $name, string $contents): ExpertProjectMaterial
    {
        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent($name, $contents)],
            ['Accept' => 'application/json'],
        )->assertCreated();

        return $project->materials()->where('public_id', $response->json('public_id'))->firstOrFail();
    }

    private function material(ExpertProject $project, User $user, string $name, int $size): ExpertProjectMaterial
    {
        $material = $project->materials()->create([
            'uploaded_by' => $user->id,
            'original_name' => $name,
            'storage_disk' => null,
            'storage_path' => "expert/{$project->public_id}/materials/{$name}",
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => $size,
            'category' => 'document',
            'status' => 'uploaded',
        ]);
        app(StorageUsageService::class)->register((int) $user->id, 'expert', [
            'disk' => 'local', 'path' => $material->storage_path, 'purpose' => 'material',
            'size_bytes' => $size, 'mime_type' => 'text/plain', 'original_filename' => $name,
        ], [['source_type' => $material->getTable(), 'source_id' => $material->id]]);
        return $material;
    }

    private function counter(int $userId, int $bytes, int $count): void
    {
        DB::table('storage_usages')->where('user_id', $userId)->update([
            'used_bytes' => $bytes,
            'files_count' => $count,
            'updated_at' => now(),
        ]);
    }
}
