<?php

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\ObjectStorage;
use Tests\TestCase;

class S1StorageIntegrationTest extends TestCase
{
    public function test_s1_object_completes_private_storage_lifecycle_when_credentials_are_configured(): void
    {
        $disk = config('filesystems.disks.s1', []);
        $required = ['key', 'secret', 'bucket', 'region', 'endpoint'];
        $configured = collect($required)->every(fn (string $key) => filled($disk[$key] ?? null));

        if (!$configured) {
            $this->markTestSkipped('S1 integration credentials are not configured.');
        }

        $storage = app(ObjectStorage::class);
        $content = 's1-integration-' . bin2hex(random_bytes(12));
        $key = $storage->storeBytes('health-check', $content, 'txt');

        try {
            $this->assertTrue($storage->exists(ObjectStorage::DISK, $key));
            $this->assertSame(strlen($content), $storage->size(ObjectStorage::DISK, $key));
            $this->assertSame(
                $content,
                $storage->withTemporaryFile(
                    ObjectStorage::DISK,
                    $key,
                    'txt',
                    fn (string $path) => file_get_contents($path),
                ),
            );
        } finally {
            $storage->delete(ObjectStorage::DISK, $key);
        }

        $this->assertFalse($storage->exists(ObjectStorage::DISK, $key));
    }
}
