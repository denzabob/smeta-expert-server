<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Expert\ExpertStorageService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Explicit real-provider check. Run this file directly with
 * EXPERT_S1_INTEGRATION_ENABLED=true and the S1 disk configured in the test environment.
 */
class ExpertS1StorageIntegrationTest extends TestCase
{
    public function test_real_s1_object_stream_read_hash_and_delete_lifecycle(): void
    {
        $enabled = filter_var(env('EXPERT_S1_INTEGRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        if (! $enabled) {
            $this->markTestSkipped('Set EXPERT_S1_INTEGRATION_ENABLED=true to use the configured S1 bucket.');
        }

        $this->assertSame('s1', config('expert.storage.disk'));
        $this->assertSame('private', config('filesystems.disks.s1.visibility'));
        $this->assertTrue(config('filesystems.disks.s1.stream_reads'));

        $storage = app(ExpertStorageService::class);
        $key = 'expert/integration-checks/'.Str::uuid().'/sample.txt';
        $contents = 'Prism Expert S1 integration check '.Str::uuid();
        $source = fopen('php://temp', 'w+b');
        $this->assertIsResource($source);
        fwrite($source, $contents);
        rewind($source);
        $writeAttempted = false;
        $temporaryPath = null;

        try {
            $writeAttempted = true;
            $storage->putStream('s1', $key, $source);
            $this->assertTrue($storage->exists('s1', $key));
            $this->assertSame(strlen($contents), $storage->size('s1', $key));
            $this->assertStringContainsString('text/plain', $storage->mimeType('s1', $key));
            $this->assertSame(hash('sha256', $contents), $storage->sha256('s1', $key));

            $readStream = $storage->readStream('s1', $key);
            try {
                $this->assertSame($contents, stream_get_contents($readStream));
            } finally {
                fclose($readStream);
            }

            $temporaryContents = $storage->withTemporaryFile('s1', $key, function (string $path) use (&$temporaryPath): string {
                $temporaryPath = $path;

                return (string) file_get_contents($path);
            });
            $this->assertSame($contents, $temporaryContents);
            $this->assertIsString($temporaryPath);
            $this->assertFileDoesNotExist($temporaryPath);

            $storage->delete('s1', $key);
            $writeAttempted = false;
            $this->assertFalse($storage->exists('s1', $key));
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if ($writeAttempted) {
                $storage->delete('s1', $key);
            }
        }
    }
}
