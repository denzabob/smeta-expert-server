<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\StatisticalImportPreviewCacheKey;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportPreviewStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Previews\StatisticalImportPreview;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Jobs\RunStatisticalImportPreviewJob;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\PriceIndices\Support\BuildsStatisticalImportWorkbook;
use Tests\TestCase;

class PriceIndicesAsyncPreviewApiTest extends TestCase
{
    use BuildsStatisticalImportWorkbook;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('price_indices.enabled', true);
        Config::set('price_indices.admin_only', true);
        Storage::fake($this->importTestDisk);
    }

    public function test_preview_endpoints_preserve_exact_role_access(): void
    {
        Queue::fake();
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $preview = $this->previewFor($file);
        $requests = [
            ['POST', "/api/indices/admin/source-files/{$file->public_id}/preview"],
            ['GET', "/api/indices/admin/previews/{$preview->public_id}"],
            ['GET', "/api/indices/admin/previews/{$preview->public_id}/result"],
            ['POST', "/api/indices/admin/previews/{$preview->public_id}/retry"],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
        foreach ([['user', 301], ['auditor', 302], ['user', 1]] as [$role, $id]) {
            $this->actingAsRole($role, $id);
            foreach ($requests as [$method, $uri]) {
                $this->json($method, $uri)->assertForbidden();
            }
        }
        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson("/api/indices/admin/previews/{$preview->public_id}")
                ->assertOk()->assertJsonPath('data.public_id', $preview->public_id);
        }
    }

    public function test_start_is_async_and_reuses_pending_running_and_ready_previews(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $before = [StatisticalImport::count(), StatisticalObservation::count(), StatisticalClassifierItem::count()];

        $first = $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('meta.queued', true)
            ->assertJsonPath('meta.cached', false)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.source_file.stored_path');
        $publicId = $first->json('data.public_id');
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 1);

        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertAccepted()->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('meta.queued', false)->assertJsonPath('meta.reused', true);
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 1);

        $preview = StatisticalImportPreview::query()->where('public_id', $publicId)->sole();
        $preview->forceFill(['status' => StatisticalImportPreviewStatus::Running])->save();
        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertAccepted()->assertJsonPath('data.public_id', $publicId);

        $preview->forceFill([
            'status' => StatisticalImportPreviewStatus::Ready,
            'finished_at' => now(),
            'expires_at' => now()->addHour(),
            'result_json' => ['counts' => ['numeric' => 12]],
        ])->save();
        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertOk()->assertJsonPath('data.public_id', $publicId)
            ->assertJsonPath('meta.cached', true)->assertJsonPath('meta.queued', false);

        $this->assertSame(1, StatisticalImportPreview::count());
        $this->assertSame($before, [StatisticalImport::count(), StatisticalObservation::count(), StatisticalClassifierItem::count()]);
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 1);
    }

    public function test_failed_and_expired_start_create_new_attempts(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();

        foreach ([StatisticalImportPreviewStatus::Failed, StatisticalImportPreviewStatus::Expired] as $status) {
            $path = $this->writeRepresentativeWorkbook("{$status->value}.xlsx");
            $file = $this->sourceFileWithSyntheticHash($dataset, $path, "start-{$status->value}");
            $old = $this->previewFor($file, $status, [
                'failed_at' => $status === StatisticalImportPreviewStatus::Failed ? now() : null,
                'expires_at' => now()->subMinute(),
            ]);

            $response = $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
                ->assertAccepted()->assertJsonPath('meta.queued', true);
            $this->assertNotSame($old->public_id, $response->json('data.public_id'));
            $this->assertSame(2, StatisticalImportPreview::query()->where('cache_key', $old->cache_key)->count());
        }
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 2);
    }

    public function test_preview_job_persists_ready_result_counters_ttl_and_metrics(): void
    {
        Queue::fake();
        Config::set('price_indices.imports.preview_cache_ttl_hours', 24);
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $publicId = $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertAccepted()->json('data.public_id');

        app()->call([new RunStatisticalImportPreviewJob($publicId), 'handle']);
        $preview = StatisticalImportPreview::query()->where('public_id', $publicId)->sole();

        $this->assertSame(StatisticalImportPreviewStatus::Ready, $preview->status);
        $this->assertSame(4, $preview->sheets_total);
        $this->assertSame(3, $preview->supported_sheets);
        $this->assertSame(1, $preview->ignored_sheets);
        $this->assertSame(7, $preview->commodity_occurrences);
        $this->assertSame(3, $preview->unique_classifier_items);
        $this->assertSame(14, $preview->observation_candidates);
        $this->assertSame(12, $preview->numeric_count);
        $this->assertSame(1, $preview->missing_count);
        $this->assertSame(1, $preview->footnoted_count);
        $this->assertSame(0, $preview->fatal_errors_count);
        $this->assertNotNull($preview->result_json);
        $this->assertNotNull($preview->finished_at);
        $this->assertNotNull($preview->expires_at);
        $this->assertTrue($preview->expires_at->greaterThanOrEqualTo($preview->finished_at->copy()->addHours(23)));
        $this->assertGreaterThan(0, $preview->metadata_json['result_json_bytes']);
        $this->assertArrayHasKey('elapsed_seconds', $preview->metadata_json);
        $this->assertArrayHasKey('peak_memory_bytes', $preview->metadata_json);
        $this->assertSame(0, StatisticalImport::count());
        $this->assertSame(0, StatisticalObservation::count());
        $this->assertSame(0, StatisticalClassifierItem::count());

        $this->getJson("/api/indices/admin/previews/{$publicId}")
            ->assertOk()->assertJsonPath('data.status', 'ready')
            ->assertJsonMissingPath('data.result_json')->assertJsonMissingPath('data.id');
        $result = $this->getJson("/api/indices/admin/previews/{$publicId}/result")
            ->assertOk()->assertJsonPath('data.counts.unique_classifier_items', 3)
            ->assertJsonMissingPath('data.source_file.stored_path')
            ->assertJsonMissingPath('data.id');
        $this->assertContains('05.10.10.101.АГ', array_column($result->json('data.samples'), 'item_code'));
    }

    public function test_controlled_job_failure_is_persisted_without_throwing_paths(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeFormulaWorkbook());
        $publicId = $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertAccepted()->json('data.public_id');

        app()->call([new RunStatisticalImportPreviewJob($publicId), 'handle']);

        $preview = StatisticalImportPreview::query()->where('public_id', $publicId)->sole();
        $this->assertSame(StatisticalImportPreviewStatus::Failed, $preview->status);
        $this->assertSame('unsupported_workbook', $preview->failure_code);
        $this->assertNotNull($preview->failed_at);
        $this->getJson("/api/indices/admin/previews/{$publicId}/result")
            ->assertConflict()->assertJsonPath('code', 'preview_failed')
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('path');
    }

    public function test_result_endpoint_returns_stable_errors_for_non_ready_states(): void
    {
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $expectations = [
            StatisticalImportPreviewStatus::Pending->value => 'preview_not_ready',
            StatisticalImportPreviewStatus::Running->value => 'preview_not_ready',
            StatisticalImportPreviewStatus::Failed->value => 'preview_failed',
            StatisticalImportPreviewStatus::Expired->value => 'preview_expired',
        ];

        foreach ($expectations as $status => $code) {
            $preview = $this->previewFor($file, StatisticalImportPreviewStatus::from($status), [
                'cache_key' => hash('sha256', $status),
            ]);
            $this->getJson("/api/indices/admin/previews/{$preview->public_id}/result")
                ->assertConflict()->assertJsonPath('code', $code);
        }
        $this->getJson('/api/indices/admin/previews/00000000-0000-4000-8000-000000000000/result')
            ->assertNotFound();
    }

    public function test_retry_creates_new_attempt_only_for_failed_or_expired(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();

        foreach ([StatisticalImportPreviewStatus::Failed, StatisticalImportPreviewStatus::Expired] as $status) {
            $path = $this->writeRepresentativeWorkbook("retry-{$status->value}.xlsx");
            $file = $this->sourceFileWithSyntheticHash($dataset, $path, "retry-{$status->value}");
            $old = $this->previewFor($file, $status, [
                'expires_at' => $status === StatisticalImportPreviewStatus::Failed
                    ? now()->addHour()
                    : now()->subMinute(),
            ]);
            $response = $this->postJson("/api/indices/admin/previews/{$old->public_id}/retry")
                ->assertAccepted()->assertJsonPath('data.status', 'pending')
                ->assertJsonPath('meta.queued', true);
            $new = StatisticalImportPreview::query()->where('public_id', $response->json('data.public_id'))->sole();
            $this->assertNotSame($old->public_id, $new->public_id);
            $this->assertSame($old->cache_key, $new->cache_key);
            $this->assertSame($old->source_file_id, $new->source_file_id);
            $this->assertSame($status, $old->fresh()->status);
        }

        foreach ([
            StatisticalImportPreviewStatus::Pending,
            StatisticalImportPreviewStatus::Running,
            StatisticalImportPreviewStatus::Ready,
        ] as $status) {
            $path = $this->writeRepresentativeWorkbook("blocked-{$status->value}.xlsx");
            $file = $this->sourceFileWithSyntheticHash($dataset, $path, "blocked-{$status->value}");
            $preview = $this->previewFor($file, $status, [
                'expires_at' => $status === StatisticalImportPreviewStatus::Ready ? now()->addHour() : null,
            ]);
            $this->postJson("/api/indices/admin/previews/{$preview->public_id}/retry")
                ->assertConflict()->assertJsonPath('code', 'preview_retry_not_allowed');
        }
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 2);
    }

    public function test_dispatch_failure_marks_preview_failed(): void
    {
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('secret queue detail'));
        });

        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertStatus(503)->assertJsonPath('code', 'job_dispatch_failed')
            ->assertJsonMissingPath('exception')->assertJsonMissingPath('path');
        $preview = StatisticalImportPreview::query()->sole();
        $this->assertSame(StatisticalImportPreviewStatus::Failed, $preview->status);
        $this->assertSame('job_dispatch_failed', $preview->failure_code);
        $this->assertNotNull($preview->failed_at);
    }

    private function previewFor(
        StatisticalSourceFile $file,
        StatisticalImportPreviewStatus $status = StatisticalImportPreviewStatus::Pending,
        array $attributes = [],
    ): StatisticalImportPreview {
        $cacheKey = app(StatisticalImportPreviewCacheKey::class)->forSourceFile(
            $file,
            'producer_price_indices_by_product',
            '1.0.0',
        );

        return StatisticalImportPreview::factory()->create($attributes + [
            'dataset_id' => $file->dataset_id,
            'source_file_id' => $file->id,
            'status' => $status,
            'cache_key' => $cacheKey,
        ]);
    }

    private function sourceFileWithSyntheticHash(
        StatisticalDataset $dataset,
        string $path,
        string $identity,
    ): StatisticalSourceFile {
        return StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
            'storage_disk' => $this->importTestDisk,
            'stored_path' => $path,
            'original_filename' => basename($path),
            'sha256' => hash('sha256', $identity),
        ]);
    }

    private function actingAsRole(string $role, ?int $id = null): User
    {
        if ($id !== null) {
            $user = new User();
            $user->forceFill(['id' => $id, 'role' => $role]);
        } else {
            $user = User::factory()->create(['role' => $role]);
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
