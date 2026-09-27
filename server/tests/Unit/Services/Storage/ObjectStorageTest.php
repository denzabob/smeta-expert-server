<?php

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\ObjectStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\Storage\ObjectStorageException;
use RuntimeException;
use Tests\TestCase;

class ObjectStorageTest extends TestCase
{
    public function test_new_smeta_uploads_go_to_s1_under_the_smeta_prefix(): void
    {
        Storage::fake('s1');
        Storage::fake('local');

        $key = app(ObjectStorage::class)->storeUploaded(
            'evidence-records',
            UploadedFile::fake()->createWithContent('proof.txt', 'evidence'),
            17,
        );

        $this->assertStringStartsWith('smeta/evidence-records/17/', $key);
        Storage::disk('s1')->assertExists($key);
        Storage::disk('local')->assertMissing($key);
    }

    public function test_temporary_materialization_is_removed_after_callback(): void
    {
        Storage::fake('s1');
        $storage = app(ObjectStorage::class);
        $key = $storage->storeBytes('imports', "name,price\nchair,10", 'csv');
        $temporaryPath = null;

        $contents = $storage->withTemporaryFile('s1', $key, 'csv', function (string $path) use (&$temporaryPath) {
            $temporaryPath = $path;
            return file_get_contents($path);
        });

        $this->assertSame("name,price\nchair,10", $contents);
        $this->assertNotNull($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }

    public function test_parser_generated_screenshots_are_promoted_only_from_the_scoped_public_folder(): void
    {
        Storage::fake('s1');
        Storage::fake('public');
        Storage::disk('public')->put('screenshots/parser-result.png', 'image-bytes');

        $storage = app(ObjectStorage::class);
        $key = $storage->ingestGeneratedPath('screenshots/parser', 'screenshots/parser-result.png');

        $this->assertNotNull($key);
        $this->assertStringStartsWith('smeta/screenshots/parser/', $key);
        Storage::disk('s1')->assertExists($key);
        Storage::disk('public')->assertMissing('screenshots/parser-result.png');
        $this->assertNull($storage->ingestGeneratedPath('screenshots/parser', 'smeta/imports/private.csv'));
        $this->assertNull($storage->ingestGeneratedPath('screenshots/parser', storage_path('app/private/secret.txt')));
    }

    public function test_s1_write_failure_does_not_attempt_a_local_fallback(): void
    {
        Storage::shouldReceive('disk')
            ->once()
            ->with('s1')
            ->andThrow(new RuntimeException('backend unavailable'));
        Storage::shouldReceive('disk')->with('local')->never();

        $this->expectException(ObjectStorageException::class);

        app(ObjectStorage::class)->storeBytes('imports', 'test', 'csv');
    }
}
