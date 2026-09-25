<?php

declare(strict_types=1);

namespace Tests\Feature\Expert;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpertStorageHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_health_check_verifies_and_removes_a_temporary_s1_object(): void
    {
        config()->set('expert.storage.disk', 's1');
        config()->set('filesystems.disks.s1', [
            'driver' => 's3',
            'key' => 'test-access-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'expert-test-bucket',
            'endpoint' => 'https://s1.test.invalid',
            'visibility' => 'private',
            'stream_reads' => true,
            'throw' => true,
            'report' => false,
        ]);
        Storage::fake('s1');

        $this->artisan('expert:storage-check')
            ->expectsOutput('Expert storage disk: s1')
            ->expectsOutput('Connection: OK')
            ->expectsOutput('Write: OK')
            ->expectsOutput('Exists/stat: OK')
            ->expectsOutput('Size verification: OK')
            ->expectsOutput('Read: OK')
            ->expectsOutput('Delete: OK')
            ->expectsOutput('Verify deletion: OK')
            ->expectsOutput('Expert storage check: OK')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('s1')->allFiles('expert/health-checks'));
    }

    public function test_s1_health_check_runs_the_full_lifecycle_and_leaves_no_object(): void
    {
        config()->set('filesystems.disks.s1', [
            'driver' => 's3',
            'key' => 'test-access-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
            'bucket' => 'expert-test-bucket',
            'endpoint' => 'https://s1.test.invalid',
            'visibility' => 'private',
            'stream_reads' => true,
            'throw' => true,
            'report' => false,
        ]);
        Storage::fake('s1');

        $this->artisan('expert:storage-check --disk=s1')
            ->expectsOutput('Expert storage disk: s1')
            ->expectsOutput('Connection: OK')
            ->expectsOutput('Write: OK')
            ->expectsOutput('Exists/stat: OK')
            ->expectsOutput('Size verification: OK')
            ->expectsOutput('Read: OK')
            ->expectsOutput('Delete: OK')
            ->expectsOutput('Verify deletion: OK')
            ->expectsOutput('Expert storage check: OK')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('s1')->allFiles('expert/health-checks'));
    }

    public function test_health_check_returns_a_stable_error_without_showing_disk_details(): void
    {
        config()->set('expert.storage.disk', 'missing_test_disk');

        $this->artisan('expert:storage-check')
            ->expectsOutput('Expert storage disk: missing_test_disk')
            ->expectsOutput('Expert storage check failed (STORAGE_BACKEND_UNAVAILABLE).')
            ->assertExitCode(1);
    }
}
