<?php

namespace Tests\Unit\Services;

use App\Services\Storage\StorageQuotaException;
use App\Services\Storage\StorageQuotaService;
use App\Services\Storage\StorageUsageService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountStorageUsageTest extends TestCase
{
    private StorageUsageService $usage;
    private StorageQuotaService $quota;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::create('users', fn (Blueprint $table) => $table->id());
        DB::table('users')->insert(['id' => 1]);
        (require database_path('migrations/2026_09_28_000001_create_account_storage_tables.php'))->up();
        $this->quota = new class extends StorageQuotaService {
            public ?int $limitBytes = 20;
            public function __construct() {}
            public function limit(int $userId): array { return ['limit' => $this->limitBytes, 'plan_code' => 'fixture']; }
        };
        $this->usage = new StorageUsageService($this->quota);
    }

    public function test_shared_quota_equality_and_release_are_idempotent(): void
    {
        $this->usage->register(1, 'expert', $this->metadata('expert', 8));
        $this->usage->register(1, 'smeta', $this->metadata('smeta', 7));
        $id = $this->usage->reserve(1, 'smeta', 4);
        $this->assertSame(4, $this->usage->getUserUsage(1)['reserved_bytes']);
        try {
            $this->usage->reserve(1, 'expert', 2);
            $this->fail('Shared quota must reject projected 21/20.');
        } catch (StorageQuotaException $e) {
            $this->assertSame('STORAGE_QUOTA_EXCEEDED', $e->errorCode);
        }
        $this->assertTrue($this->usage->release($id));
        $this->assertFalse($this->usage->release($id));
        $equal = $this->usage->reserve(1, 'expert', 5);
        $this->assertSame(5, $this->usage->getUserUsage(1)['reserved_bytes']);
        $this->usage->release($equal);
    }

    public function test_null_limit_allows_zero_limit_forbids_even_empty_billable_upload(): void
    {
        $this->quota->limitBytes = null;
        $id = $this->usage->reserve(1, 'expert', 100);
        $this->usage->release($id);
        $this->quota->limitBytes = 0;
        $this->expectException(StorageQuotaException::class);
        $this->usage->reserve(1, 'smeta', 0);
    }

    public function test_actual_size_finalize_shared_links_nonbillable_and_recalculation(): void
    {
        $id = $this->usage->reserve(1, 'expert', 10);
        $file = $this->usage->finalize($id, $this->metadata('image', 8, 'image/png'), [['source_type' => 'materials', 'source_id' => 1]]);
        $this->assertSame($file, $this->usage->finalize($id, $this->metadata('image', 8, 'image/png')));
        $this->usage->link($file, 'smeta', 'evidence', 2);
        $this->usage->register(1, 'expert', $this->metadata('thumbnail', 150, 'image/png') + ['billable' => false]);
        $snapshot = $this->usage->getUserUsage(1);
        $this->assertSame(8, $snapshot['used_bytes']);
        $this->assertSame(0, $snapshot['reserved_bytes']);
        $this->assertSame(1, $snapshot['images_count']);
        $this->assertSame([], $this->usage->unlink('expert', 'materials', 1));
        $this->assertSame(8, $this->usage->getUserUsage(1)['used_bytes']);
        DB::table('storage_usages')->where('user_id', 1)->update(['used_bytes' => 100]);
        $this->assertArrayHasKey('used_bytes', $this->usage->audit(1)['mismatches']);
        $this->assertSame(8, $this->usage->recalculate(1)['used_bytes']);
        $this->assertCount(1, $this->usage->unlink('smeta', 'evidence', 2));
        $this->assertSame(0, $this->usage->getUserUsage(1)['used_bytes']);
        $this->assertSame([], $this->usage->audit(1)['mismatches']);
    }

    public function test_finalize_delta_rechecks_limit_and_outer_transaction_rolls_back_registry(): void
    {
        $this->usage->register(1, 'expert', $this->metadata('existing', 10));
        $id = $this->usage->reserve(1, 'smeta', 10);
        try {
            $this->usage->finalize($id, $this->metadata('too-large', 12));
            $this->fail('Actual size must be checked.');
        } catch (StorageQuotaException $e) {
            $this->assertSame(10, $this->usage->getUserUsage(1)['used_bytes']);
            $this->assertSame(10, $this->usage->getUserUsage(1)['reserved_bytes']);
            $this->assertFalse(DB::table('storage_files')->where('path', 'too-large')->exists());
        }
        $this->usage->release($id);
        $id = $this->usage->reserve(1, 'smeta', 10);
        DB::beginTransaction();
        $this->usage->finalize($id, $this->metadata('rolled-back', 10));
        DB::rollBack();
        $this->assertFalse(DB::table('storage_files')->where('path', 'rolled-back')->exists());
        $this->assertSame(10, $this->usage->getUserUsage(1)['reserved_bytes']);
    }

    public function test_expiry_cleanup_is_idempotent_and_downgrade_allows_delete(): void
    {
        $file = $this->usage->register(1, 'expert', $this->metadata('large', 30), [['source_type' => 'materials', 'source_id' => 1]]);
        $this->assertSame(30, $this->usage->getUserUsage(1)['used_bytes']);
        try {
            $this->usage->reserve(1, 'smeta', 1);
            $this->fail('Downgrade must reject upload.');
        } catch (StorageQuotaException $e) {
            $this->assertSame('STORAGE_QUOTA_EXCEEDED', $e->errorCode);
        }
        $this->usage->unlink('expert', 'materials', 1, $file);
        $id = $this->usage->reserve(1, 'smeta', 1);
        DB::table('storage_upload_reservations')->where('reservation_id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, $this->usage->expireReservations());
        $this->assertSame(0, $this->usage->expireReservations());
        $this->assertSame(0, $this->usage->getUserUsage(1)['reserved_bytes']);
    }

    public function test_same_locator_backfill_is_idempotent_and_inconsistent_metadata_is_rejected(): void
    {
        $first = $this->usage->register(1, 'expert', $this->metadata('shared', 8));
        $this->assertSame($first, $this->usage->register(1, 'expert', $this->metadata('shared', 8)));
        $this->assertSame(8, $this->usage->getUserUsage(1)['used_bytes']);
        $this->expectException(\RuntimeException::class);
        $this->usage->register(1, 'expert', $this->metadata('shared', 9));
    }

    public function test_consumed_reservation_cannot_finalize_another_existing_locator(): void
    {
        $reservation = $this->usage->reserve(1, 'expert', 8);
        $this->usage->finalize($reservation, $this->metadata('original', 8));
        $this->usage->register(1, 'expert', $this->metadata('unrelated', 8));
        $this->expectException(\RuntimeException::class);
        $this->usage->finalize($reservation, $this->metadata('unrelated', 8));
    }

    public function test_registry_rejects_module_conflict_without_double_accounting(): void
    {
        $this->usage->register(1, 'expert', $this->metadata('shared', 8));
        try {
            $this->usage->register(1, 'smeta', $this->metadata('shared', 8));
            $this->fail('Physical object ownership module must remain deterministic.');
        } catch (\RuntimeException $e) {
            $this->assertSame('STORAGE_OBJECT_METADATA_CONFLICT', $e->getMessage());
        }
        $snapshot = $this->usage->getUserUsage(1);
        $this->assertSame(8, $snapshot['used_bytes']);
        $this->assertSame(1, $snapshot['files_count']);
        $this->assertSame(0, $snapshot['modules']['smeta']['used_bytes']);
        $audit = $this->usage->audit(1);
        $this->assertSame(8, $audit['calculated']['used_bytes']);
        $this->assertSame(8, $audit['recorded']['used_bytes']);
    }

    public function test_quota_error_contains_account_fields_and_legacy_storage_aliases(): void
    {
        $exception = new StorageQuotaException('STORAGE_QUOTA_EXCEEDED', [
            'limit_bytes' => 20, 'used_bytes' => 15, 'reserved_bytes' => 4,
            'requested_bytes' => 2, 'available_bytes' => 1,
        ]);
        $response = $exception->toApiResponse();
        $this->assertSame(422, $response['status']);
        $this->assertSame(1, $response['body']['remaining_bytes']);
        $this->assertSame(21, $response['body']['projected_bytes']);
        $this->assertSame($response['body']['used_bytes'], $response['body']['storage']['used_bytes']);
        $this->assertSame(1, $response['body']['storage']['remaining_bytes']);
        $this->assertSame(503, (new StorageQuotaException('BILLING_LIMIT_CHECK_FAILED'))->toApiResponse()['status']);
    }

    public function test_uuid_revision_link_preserves_physical_file_until_the_last_reference_is_removed(): void
    {
        $revisionId = 'b3a90c47-5394-41e4-83e0-b77f4cf60215';
        $file = $this->usage->register(1, 'smeta', $this->metadata('revision-screenshot', 8, 'image/png'), [
            ['source_type' => 'evidence_artifacts', 'source_id' => 1],
            ['source_type' => 'project_revisions', 'source_id' => $revisionId],
        ]);
        $this->assertSame($revisionId, DB::table('storage_file_links')->where('source_type', 'project_revisions')->value('source_id'));
        $this->assertSame([], $this->usage->unlink('smeta', 'evidence_artifacts', 1));
        $this->assertSame(8, $this->usage->getUserUsage(1)['used_bytes']);
        $this->assertSame('active', DB::table('storage_files')->where('id', $file)->value('status'));
        $this->assertCount(1, $this->usage->unlink('smeta', 'project_revisions', $revisionId));
        $this->assertSame(0, $this->usage->getUserUsage(1)['used_bytes']);
    }

    public function test_usage_audit_defaults_to_all_and_reports_separate_mismatch_counts(): void
    {
        $this->usage->register(1, 'expert', $this->metadata('audit', 8));
        $this->app->instance(StorageUsageService::class, $this->usage);
        $this->artisan('storage:usage-audit')
            ->expectsOutput('Usage mismatches: 0; duplicate objects: 0')
            ->expectsOutput('Used mismatches: 0; reserved mismatches: 0; module mismatches: 0; category mismatches: 0')
            ->assertExitCode(0);
        DB::table('storage_usages')->where('user_id', 1)->update(['used_bytes' => 9, 'images_count' => 1]);
        $this->artisan('storage:usage-audit', ['--user' => 1])
            ->expectsOutput('Usage mismatches: 2; duplicate objects: 0')
            ->expectsOutput('Used mismatches: 1; reserved mismatches: 0; module mismatches: 0; category mismatches: 1')
            ->assertExitCode(1);
    }

    private function metadata(string $path, int $size, string $mime = 'application/pdf'): array
    {
        return ['disk' => 's1', 'path' => $path, 'purpose' => 'test', 'size_bytes' => $size, 'mime_type' => $mime];
    }
}
