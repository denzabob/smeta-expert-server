<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Expert\ExpertProject;
use App\Models\User;
use App\Services\Expert\ExpertMaterialContextBuilder;
use App\Services\Expert\ExpertStorageService;
use App\Services\Expert\ExpertStorageUsageService;
use App\Services\Expert\ExpertVisionImagePreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gated end-to-end check of the Expert upload lifecycle against a real S1 bucket.
 * Run with EXPERT_S1_INTEGRATION_ENABLED=true and an isolated S1 test bucket.
 */
final class ExpertS1StorageCutoverIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_expert_upload_download_context_preview_and_delete_are_s1_backed(): void
    {
        if (! filter_var(env('EXPERT_S1_INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set EXPERT_S1_INTEGRATION_ENABLED=true to use the configured S1 test bucket.');
        }

        $this->assertSame('s1', config('expert.storage.disk'));
        $this->assertSame('private', config('filesystems.disks.s1.visibility'));
        $this->assertTrue((bool) config('filesystems.disks.s1.stream_reads'));
        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
            $this->assertNotSame('', trim((string) config("filesystems.disks.s1.{$field}")), "Missing S1 setting: {$field}");
        }

        Storage::fake('local');
        $storage = app(ExpertStorageService::class);
        $user = User::factory()->create();
        $project = ExpertProject::query()->create([
            'user_id' => $user->id,
            'name' => 'S1 cutover integration check',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $keys = [];

        try {
            $text = 'S1 cutover synthetic context '.Str::uuid();
            $textResponse = $this->actingAs($user, 'sanctum')->post(
                "/api/expert/projects/{$project->public_id}/materials",
                ['file' => UploadedFile::fake()->createWithContent('s1-cutover.txt', $text)],
                ['Accept' => 'application/json'],
            )->assertCreated();
            $textMaterial = $project->materials()->where('public_id', $textResponse->json('public_id'))->firstOrFail();
            $keys[] = $textMaterial->storageKey();

            $this->assertSame('s1', $textMaterial->storageDisk());
            $this->assertSame(strlen($text), $storage->size('s1', $textMaterial->storageKey()));
            $this->assertSame(hash('sha256', $text), $storage->sha256('s1', $textMaterial->storageKey()));
            $this->assertSame($text, $this->get("/api/expert/materials/{$textMaterial->public_id}/download")->assertOk()->streamedContent());
            $context = app(ExpertMaterialContextBuilder::class)->build($project, [$textMaterial->public_id]);
            $this->assertStringContainsString($text, $context[0]['text']);
            $this->assertSame(strlen($text), app(ExpertStorageUsageService::class)->getUserUsage($user)->usedBytes);
            $this->assertSame(0, app(ExpertStorageUsageService::class)->getUserUsage($user)->reservedBytes);
            $this->assertFalse(Storage::disk('local')->exists($textMaterial->storageKey()));

            $imageResponse = $this->post(
                "/api/expert/projects/{$project->public_id}/materials",
                ['file' => UploadedFile::fake()->image('s1-cutover.jpg', 72, 54)],
                ['Accept' => 'application/json'],
            )->assertCreated();
            $image = $project->materials()->where('public_id', $imageResponse->json('public_id'))->firstOrFail();
            $keys[] = $image->storageKey();

            $this->assertSame('s1', $image->storageDisk());
            $this->assertTrue($storage->exists('s1', $image->storageKey()));
            $this->assertNotSame('', app(ExpertVisionImagePreparer::class)->prepare($image)->bytes);
            $imageContent = $this->get("/api/expert/materials/{$image->public_id}/content")->assertOk();
            $this->assertNotSame('', $imageContent->streamedContent());
            $thumbnailUrl = "/api/expert/materials/{$image->public_id}/thumbnail";
            $thumbnail = $this->get($thumbnailUrl)->assertOk();
            $etag = (string) $thumbnail->headers->get('etag');
            $this->assertNotSame('', $etag);
            $this->assertNotSame('', $thumbnail->streamedContent());
            $this->withHeaders(['If-None-Match' => $etag])
                ->get($thumbnailUrl)
                ->assertStatus(304);

            $this->assertSame(
                $textMaterial->size + $image->size,
                app(ExpertStorageUsageService::class)->getUserUsage($user)->usedBytes,
            );
            $this->assertSame(0, app(ExpertStorageUsageService::class)->getUserUsage($user)->reservedBytes);

            $this->deleteJson("/api/expert/materials/{$textMaterial->public_id}")->assertNoContent();
            $this->deleteJson("/api/expert/materials/{$image->public_id}")->assertNoContent();
            $this->assertFalse($storage->exists('s1', $textMaterial->storageKey()));
            $this->assertFalse($storage->exists('s1', $image->storageKey()));
            $this->assertSame(0, app(ExpertStorageUsageService::class)->getUserUsage($user)->usedBytes);
            $this->assertSame(0, app(ExpertStorageUsageService::class)->getUserUsage($user)->reservedBytes);
        } finally {
            foreach ($keys as $key) {
                try {
                    $storage->delete('s1', $key);
                } catch (\Throwable) {
                    // Best-effort cleanup is limited to this test's unique object keys.
                }
            }
        }
    }
}
