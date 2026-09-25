<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\Expert\ExpertMaterialContextBuilder;
use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageService;
use App\Services\Expert\ExpertVisionImagePreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ExpertStorageAbstractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_disk_upload_read_download_and_delete_keep_private_contract(): void
    {
        Storage::fake('local');
        $this->configureFakeS1();
        config()->set('expert.storage.disk', 's1');

        [$user, $project] = $this->project();
        $contents = 'Object storage marker EXPERT-STORAGE-1862';
        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent('context.txt', $contents)],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('original_name', 'context.txt')
            ->assertJsonMissingPath('storage_path')
            ->assertJsonMissingPath('storage_disk');

        $material = $project->materials()->where('public_id', $response->json('public_id'))->firstOrFail();
        $this->assertSame('s1', $material->storage_disk);
        Storage::disk('s1')->assertExists($material->storage_path);

        $download = $this->get("/api/expert/materials/{$material->public_id}/download")->assertOk();
        $this->assertSame($contents, $download->streamedContent());
        $context = app(ExpertMaterialContextBuilder::class)->build($project, [$material->public_id]);
        $this->assertStringContainsString('EXPERT-STORAGE-1862', $context[0]['text']);

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')->get("/api/expert/materials/{$material->public_id}/download")->assertForbidden();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/expert/materials/{$material->public_id}")->assertNoContent();
        Storage::disk('s1')->assertMissing($material->storage_path);
    }

    public function test_configured_disk_image_preview_thumbnail_and_conditional_response_work(): void
    {
        Storage::fake('local');
        $this->configureFakeS1();
        config()->set('expert.storage.disk', 's1');

        [$user, $project] = $this->project();
        $upload = $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->image('photo.jpg', 96, 72)],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $material = $project->materials()->where('public_id', $upload->json('public_id'))->firstOrFail();

        $content = $this->get("/api/expert/materials/{$material->public_id}/content")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
        $this->assertNotSame('', $content->streamedContent());

        $thumbnailUrl = "/api/expert/materials/{$material->public_id}/thumbnail";
        $thumbnail = $this->get($thumbnailUrl)->assertOk()->assertHeader('content-type', 'image/jpeg');
        $etag = $thumbnail->headers->get('etag');
        $this->assertNotSame('', $thumbnail->streamedContent());
        $this->withHeaders(['If-None-Match' => $etag])->get($thumbnailUrl)->assertStatus(304);

        $cacheDirectory = "expert/{$project->public_id}/thumbnails/{$material->public_id}";
        $this->assertNotEmpty(Storage::disk('local')->allFiles($cacheDirectory));
        $usage = app(\App\Services\Expert\ExpertStorageUsageService::class)->getUserUsage($user);
        $this->assertSame($material->size, $usage->usedBytes);
        $this->assertSame(0, $usage->reservedBytes);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/expert/projects/{$project->public_id}")->assertNoContent();
        Storage::disk('s1')->assertMissing($material->storage_path);
        $this->assertDatabaseMissing('expert_storage_cleanup_tasks', [
            'disk' => 's1',
            'path' => "expert/{$project->public_id}",
        ]);
    }

    public function test_legacy_material_without_disk_uses_local_and_temporary_files_are_removed(): void
    {
        Storage::fake('local');
        [$user, $project] = $this->project();
        $key = "expert/{$project->public_id}/materials/legacy.txt";
        Storage::disk('local')->put($key, 'legacy local material');
        $material = $project->materials()->create([
            'uploaded_by' => $user->id,
            'original_name' => 'legacy.txt',
            'storage_path' => $key,
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 21,
            'category' => 'document',
            'status' => 'uploaded',
        ]);

        $this->assertNull($material->storage_disk);
        $this->assertSame('local', $material->storageDisk());
        $this->assertSame('legacy local material', app(ExpertStorageService::class)->read('local', $material->storageKey()));

        $temporaryPath = null;
        $result = app(ExpertStorageService::class)->withTemporaryFileFromContents('temporary parser bytes', function (string $path) use (&$temporaryPath): string {
            $temporaryPath = $path;

            return file_get_contents($path);
        });
        $this->assertSame('temporary parser bytes', $result);
        $this->assertIsString($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);

        try {
            app(ExpertStorageService::class)->withTemporaryFileFromContents('temporary parser bytes', function (string $path) use (&$temporaryPath): never {
                $temporaryPath = $path;
                throw new \RuntimeException('expected parser failure');
            });
            $this->fail('Expected temporary parser callback to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('expected parser failure', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($temporaryPath);
    }

    public function test_storage_failures_have_stable_codes_without_filesystem_paths(): void
    {
        Storage::fake('local');
        $storage = app(ExpertStorageService::class);

        try {
            $storage->read('local', 'expert/missing.txt');
            $this->fail('Expected missing storage object to fail.');
        } catch (ExpertStorageException $exception) {
            $this->assertSame(ExpertStorageException::FILE_NOT_FOUND, $exception->failureCode);
            $this->assertStringNotContainsString('storage/app', $exception->getMessage());
        }

        try {
            $storage->exists('unknown_disk', 'expert/file.txt');
            $this->fail('Expected unknown disk to fail.');
        } catch (ExpertStorageException $exception) {
            $this->assertSame(ExpertStorageException::STORAGE_UNAVAILABLE, $exception->failureCode);
        }
    }

    public function test_local_persistent_material_writes_are_logged_and_blocked(): void
    {
        Log::spy();
        Storage::fake('local');
        $storage = app(ExpertStorageService::class);
        $key = 'expert/'.(string) Str::uuid().'/materials/blocked.txt';

        try {
            $storage->put('local', $key, 'must stay out of local persistent storage');
            $this->fail('Expected persistent Expert local write to be rejected.');
        } catch (ExpertStorageException $exception) {
            $this->assertSame(ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE, $exception->failureCode);
        }

        Storage::disk('local')->assertMissing($key);
        Log::shouldHaveReceived('warning')->with('Unexpected local write to persistent Expert material storage.', Mockery::on(
            fn (array $context): bool => ($context['disk'] ?? null) === 'local'
                && ($context['operation'] ?? null) === 'put'
                && ($context['error_code'] ?? null) === ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE
                && ! array_key_exists('storage_key', $context),
        ));
    }

    public function test_new_material_uploads_are_s1_only_while_legacy_local_reads_remain_supported(): void
    {
        Storage::fake('local');
        $this->configureFakeS1();
        config()->set('expert.storage.disk', 'local');

        [$user, $project] = $this->project();
        $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent('local.txt', 'LOCAL-MUST-NOT-WRITE')],
            ['Accept' => 'application/json'],
        )->assertStatus(503)->assertJsonPath('code', ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE);
        $this->assertDatabaseCount('expert_project_materials', 0);
        $this->assertSame([], Storage::disk('local')->allFiles("expert/{$project->public_id}"));
        $this->assertSame([], Storage::disk('s1')->allFiles("expert/{$project->public_id}"));

        config()->set('expert.storage.disk', 's1');
        $s1Response = $this->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent('remote.txt', 'S1-PRIMARY-4261')],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $s1Material = $project->materials()->where('public_id', $s1Response->json('public_id'))->firstOrFail();
        $imageResponse = $this->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->image('remote.jpg', 80, 60)],
            ['Accept' => 'application/json'],
        )->assertCreated();
        $image = $project->materials()->where('public_id', $imageResponse->json('public_id'))->firstOrFail();

        $this->assertSame('s1', $s1Material->storageDisk());
        $this->assertSame('s1', $image->storageDisk());
        $this->assertMatchesRegularExpression(
            '#^expert/'.preg_quote($project->public_id, '#').'/materials/[0-9a-f-]{36}\.txt$#',
            $s1Material->storageKey(),
        );
        $this->assertStringNotContainsString('\\', $s1Material->storageKey());
        $this->assertSame('private', config('filesystems.disks.s1.visibility'));
        $this->assertTrue(config('filesystems.disks.s1.stream_reads'));
        Storage::disk('s1')->assertExists($s1Material->storageKey());

        $materials = app(ExpertMaterialContextBuilder::class)->build($project, [$s1Material->public_id]);
        $this->assertStringContainsString('S1-PRIMARY-4261', $materials[0]['text']);
        $this->assertSame(
            hash('sha256', 'S1-PRIMARY-4261'),
            app(ExpertStorageService::class)->sha256('s1', $s1Material->storageKey(), app(ExpertStorageService::class)->contextForMaterial($s1Material)),
        );

        $preparedImage = app(ExpertVisionImagePreparer::class)->prepare($image);
        $this->assertSame('image/jpeg', $preparedImage->mimeType);
        $this->assertNotSame('', $preparedImage->bytes);
        $this->get("/api/expert/materials/{$image->public_id}/thumbnail")->assertOk();
        $this->assertNotEmpty(Storage::disk('local')->allFiles("expert/{$project->public_id}/thumbnails/{$image->public_id}"));

        $this->deleteJson("/api/expert/projects/{$project->public_id}")->assertNoContent();
        Storage::disk('s1')->assertMissing($s1Material->storageKey());
        Storage::disk('s1')->assertMissing($image->storageKey());
        $this->assertSame([], Storage::disk('local')->allFiles("expert/{$project->public_id}/thumbnails"));
        $this->assertDatabaseMissing('expert_storage_cleanup_tasks', [
            'disk' => 's1',
            'path' => "expert/{$project->public_id}",
        ]);
    }

    public function test_unreachable_s3_endpoint_returns_safe_error_and_does_not_create_material(): void
    {
        Log::spy();
        [$user, $project] = $this->project();
        config()->set('expert.storage.disk', 's1');
        config()->set('filesystems.disks.s1', [
            'driver' => 's3',
            'key' => 'test-access-key',
            'secret' => 'test-secret-never-log',
            'region' => 'us-east-1',
            'bucket' => 'expert-test-bucket',
            'endpoint' => 'http://127.0.0.1:9',
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'stream_reads' => true,
            'http' => ['connect_timeout' => 0.2, 'timeout' => 0.2],
            'retries' => 0,
            'throw' => true,
            'report' => false,
        ]);
        Storage::purge('s1');

        $response = $this->actingAs($user, 'sanctum')->post(
            "/api/expert/projects/{$project->public_id}/materials",
            ['file' => UploadedFile::fake()->createWithContent('not-stored.txt', 'NETWORK-FAILURE-7312')],
            ['Accept' => 'application/json'],
        )->assertStatus(503)->assertJsonPath('code', ExpertStorageException::WRITE_FAILED);

        $this->assertStringNotContainsString('127.0.0.1', $response->getContent());
        $this->assertStringNotContainsString('test-secret-never-log', $response->getContent());
        $this->assertDatabaseCount('expert_project_materials', 0);
        $this->assertDatabaseHas('expert_storage_usages', [
            'user_id' => $user->id,
            'originals_bytes' => 0,
            'reserved_bytes' => 0,
            'materials_count' => 0,
        ]);
        $this->assertDatabaseHas('expert_storage_upload_reservations', [
            'user_id' => $user->id,
            'status' => 'released',
        ]);
        Log::shouldHaveReceived('warning')->with('Expert storage operation failed.', Mockery::on(
            fn (array $context): bool => ($context['operation'] ?? null) === 'put_file'
                && ($context['disk'] ?? null) === 's1'
                && ($context['project_id'] ?? null) === $project->id
                && ($context['error_code'] ?? null) === ExpertStorageException::WRITE_FAILED
                && ! array_key_exists('storage_key', $context)
                && ! array_key_exists('exception_message', $context),
        ));
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
    private function project(): array
    {
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'Storage abstraction',
            'domain' => 'other',
            'work_type' => 'other',
        ]);

        return [$user, $project];
    }
}
