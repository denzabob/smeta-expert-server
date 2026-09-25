<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertStorageMigrationService;
use App\Services\Expert\ExpertStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Explicit live-provider migration check. Run separately with
 * EXPERT_S1_INTEGRATION_ENABLED=true and a dedicated S1 test bucket.
 */
final class ExpertS1StorageMigrationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_s1_migration_hash_switch_rollback_and_exact_object_cleanup(): void
    {
        if (! filter_var(env('EXPERT_S1_INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('Set EXPERT_S1_INTEGRATION_ENABLED=true to use the configured S1 test bucket.');
        }

        $this->assertSame('private', config('filesystems.disks.s1.visibility'));
        $this->assertTrue((bool) config('filesystems.disks.s1.stream_reads'));
        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
            $this->assertNotSame('', trim((string) config("filesystems.disks.s1.{$field}")), "Missing S1 setting: {$field}");
        }

        $storage = app(ExpertStorageService::class);
        $projectPublicId = (string) Str::uuid();
        $user = User::factory()->create();
        $project = ExpertProject::create([
            'user_id' => $user->id,
            'name' => 'Isolated S1 migration integration check',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $project->forceFill(['public_id' => $projectPublicId])->save();
        $sourceKey = "expert/{$projectPublicId}/materials/local-source.txt";
        $material = null;
        $targetKey = null;
        $contents = 'Synthetic S1 migration integration payload '.Str::uuid();

        try {
            $storage->put('local', $sourceKey, $contents);
            $material = $project->materials()->create([
                'uploaded_by' => $user->id,
                'original_name' => 'integration-check.txt',
                'storage_path' => $sourceKey,
                'mime_type' => 'text/plain',
                'extension' => 'txt',
                'size' => strlen($contents),
                'category' => 'document',
                'status' => 'uploaded',
            ]);
            $targetKey = "expert/{$projectPublicId}/materials/{$material->public_id}.txt";

            $result = app(ExpertStorageMigrationService::class)->migrate(['material_id' => $material->id], false, 1);
            $this->assertSame(1, $result['switched']);
            $this->assertTrue($result['accounting_unchanged']);
            $this->assertSame('s1', $material->fresh()->storageDisk());
            $this->assertSame($targetKey, $material->fresh()->storageKey());
            $this->assertSame(hash('sha256', $contents), $storage->sha256('s1', $targetKey));
            $this->assertSame($contents, $storage->read('s1', $targetKey));

            $rollback = app(ExpertStorageMigrationService::class)->rollback((int) $material->id);
            $this->assertTrue($rollback['success']);
            $this->assertSame('local', $material->fresh()->storageDisk());
            $this->assertSame($contents, $storage->read('local', $sourceKey));
            $this->assertTrue($storage->exists('s1', $targetKey));
        } finally {
            if (is_string($targetKey)) {
                try {
                    $storage->delete('s1', $targetKey);
                } catch (\Throwable) {
                    // Best-effort cleanup is scoped to this unique integration object key.
                }
            }
            try {
                $storage->delete('local', $sourceKey);
            } catch (\Throwable) {
                // Best-effort cleanup is scoped to this unique integration object key.
            }
            if ($material !== null) {
                $material->delete();
            }
            $project->delete();
        }
    }
}
