<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalObservationMissingReason;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImportIssue;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use App\Jobs\RunStatisticalImportJob;
use App\Jobs\RunStatisticalImportPreviewJob;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\PriceIndices\Support\BuildsStatisticalImportWorkbook;
use Tests\TestCase;

class PriceIndicesAdminImportApiTest extends TestCase
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

    public function test_new_routes_preserve_exact_role_access(): void
    {
        $dataset = StatisticalDataset::factory()->create();
        $file = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $import = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $file->id,
        ]);
        $requests = $this->adminImportRequests($dataset, $file, $import);

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }

        foreach ([['user', 200], ['auditor', 201], ['user', 1]] as [$role, $id]) {
            $this->actingAsRole($role, $id);
            foreach ($requests as [$method, $uri]) {
                $this->json($method, $uri)->assertForbidden();
            }
        }

        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/indices/admin/imports')->assertOk();
        }
    }

    public function test_preview_is_queued_and_returns_stable_start_errors_without_paths(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $before = [StatisticalImport::count(), StatisticalObservation::count(), StatisticalClassifierItem::count()];

        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/preview")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source_file.public_id', $file->public_id)
            ->assertJsonPath('data.importer.code', 'producer_price_indices_by_product')
            ->assertJsonPath('meta.queued', true)
            ->assertJsonPath('meta.cached', false)
            ->assertJsonMissingPath('data.source_file.stored_path')
            ->assertJsonMissingPath('data.source_file.id');
        $this->assertSame($before, [StatisticalImport::count(), StatisticalObservation::count(), StatisticalClassifierItem::count()]);
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 1);

        $inactive = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Approved,
        ]);
        $this->postJson("/api/indices/admin/source-files/{$inactive->public_id}/preview")
            ->assertConflict()->assertJsonPath('code', 'source_file_not_active');

        $missing = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
            'storage_disk' => $this->importTestDisk,
            'stored_path' => 'missing.xlsx',
        ]);
        $this->postJson("/api/indices/admin/source-files/{$missing->public_id}/preview")
            ->assertNotFound()->assertJsonPath('code', 'source_file_missing');

        $unsupportedDataset = StatisticalDataset::factory()->create(['code' => 'unsupported_api_dataset']);
        Storage::disk($this->importTestDisk)->put('unsupported.xlsx', 'not parsed during start');
        $unsupported = StatisticalSourceFile::factory()->create([
            'dataset_id' => $unsupportedDataset->id,
            'status' => SourceFileStatus::Active,
            'storage_disk' => $this->importTestDisk,
            'stored_path' => 'unsupported.xlsx',
        ]);
        $this->postJson("/api/indices/admin/source-files/{$unsupported->public_id}/preview")
            ->assertUnprocessable()->assertJsonPath('code', 'unsupported_dataset');

        $formula = $this->sourceFileForWorkbook($dataset, $this->writeFormulaWorkbook());
        $this->postJson("/api/indices/admin/source-files/{$formula->public_id}/preview")
            ->assertAccepted()->assertJsonPath('data.status', 'pending');
        Queue::assertPushed(RunStatisticalImportPreviewJob::class, 2);
    }

    public function test_start_import_is_async_and_duplicate_policy_is_explicit(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());

        $response = $this->postJson("/api/indices/admin/source-files/{$file->public_id}/imports")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.dataset.public_id', $dataset->public_id)
            ->assertJsonPath('data.source_file.public_id', $file->public_id)
            ->assertJsonPath('meta.queued', true)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.successful_dedupe_key');

        $import = StatisticalImport::query()->where('public_id', $response->json('data.public_id'))->sole();
        $this->assertSame(0, $import->observations()->count());
        Queue::assertPushed(RunStatisticalImportJob::class, 1);

        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/imports")
            ->assertConflict()->assertJsonPath('code', 'import_already_running');
        $this->assertSame(1, $file->imports()->count());

        $file->forceFill(['status' => SourceFileStatus::Approved])->save();
        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/imports")
            ->assertConflict()->assertJsonPath('code', 'source_file_not_active');

        $unsupportedDataset = StatisticalDataset::factory()->create(['code' => 'unsupported_start_dataset']);
        $unsupportedFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $unsupportedDataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $this->postJson("/api/indices/admin/source-files/{$unsupportedFile->public_id}/imports")
            ->assertUnprocessable()->assertJsonPath('code', 'unsupported_dataset');
    }

    public function test_dispatch_failure_marks_new_attempt_failed(): void
    {
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue backend unavailable'));
        });

        $this->postJson("/api/indices/admin/source-files/{$file->public_id}/imports")
            ->assertStatus(503)
            ->assertJsonPath('code', 'job_dispatch_failed')
            ->assertJsonMissingPath('exception');

        $import = $file->imports()->sole();
        $this->assertSame(StatisticalImportStatus::Failed, $import->status);
        $this->assertSame('job_dispatch_failed', $import->failure_code);
        $this->assertNotNull($import->failed_at);
    }

    public function test_duplicate_start_maps_every_terminal_and_active_status_to_stable_code(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $storedPath = $this->writeRepresentativeWorkbook('duplicate-statuses.xlsx');
        $expectations = [
            StatisticalImportStatus::Importing->value => 'import_already_running',
            StatisticalImportStatus::Validating->value => 'import_already_running',
            StatisticalImportStatus::ReadyForPublish->value => 'import_already_ready',
            StatisticalImportStatus::Published->value => 'import_already_published',
            StatisticalImportStatus::Superseded->value => 'import_already_completed',
            StatisticalImportStatus::Failed->value => 'import_retry_required',
        ];

        foreach ($expectations as $status => $code) {
            $file = StatisticalSourceFile::factory()->create([
                'dataset_id' => $dataset->id,
                'status' => SourceFileStatus::Active,
                'storage_disk' => $this->importTestDisk,
                'stored_path' => $storedPath,
                'sha256' => hash('sha256', $status),
            ]);
            StatisticalImport::factory()->create([
                'dataset_id' => $dataset->id,
                'source_file_id' => $file->id,
                'status' => StatisticalImportStatus::from($status),
            ]);

            $this->postJson("/api/indices/admin/source-files/{$file->public_id}/imports")
                ->assertConflict()
                ->assertJsonPath('code', $code);
        }
        Queue::assertNothingPushed();
    }

    public function test_import_list_and_detail_hide_internal_fields_for_all_statuses(): void
    {
        $this->actingAsRole('admin');
        $dataset = StatisticalDataset::factory()->create();
        $file = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $statuses = StatisticalImportStatus::cases();
        foreach ($statuses as $index => $status) {
            StatisticalImport::factory()->create([
                'dataset_id' => $dataset->id,
                'source_file_id' => $file->id,
                'attempt_no' => $index + 1,
                'status' => $status,
                'failure_code' => $status === StatisticalImportStatus::Failed ? 'fixture_failure' : null,
                'failure_message' => $status === StatisticalImportStatus::Failed ? 'Controlled failure.' : null,
                'metadata_json' => $status === StatisticalImportStatus::Importing
                    ? ['current_sheet' => '16', 'current_row' => 2000, 'progress_percent' => 40]
                    : null,
            ]);
        }

        $response = $this->getJson("/api/indices/admin/imports?dataset_public_id={$dataset->public_id}&sort=status&direction=asc&per_page=10")
            ->assertOk()->assertJsonCount(count($statuses), 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.successful_dedupe_key');

        $pending = StatisticalImport::query()->where('status', StatisticalImportStatus::Pending)->sole();
        $today = now()->format('Y-m-d');
        $this->getJson('/api/indices/admin/imports?'.http_build_query([
            'source_file_public_id' => $file->public_id,
            'status' => 'pending',
            'importer_code' => $pending->importer_code,
            'importer_version' => $pending->importer_version,
            'created_from' => $today,
            'created_to' => $today,
        ]))->assertOk()->assertJsonCount(1, 'data');

        $failed = StatisticalImport::query()->where('status', StatisticalImportStatus::Failed)->sole();
        $this->getJson("/api/indices/admin/imports/{$failed->public_id}")
            ->assertOk()
            ->assertJsonPath('data.failure.code', 'fixture_failure')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.dataset.id')
            ->assertJsonMissingPath('data.source_file.id');

        $importing = StatisticalImport::query()->where('status', StatisticalImportStatus::Importing)->sole();
        $this->getJson("/api/indices/admin/imports/{$importing->public_id}")
            ->assertJsonPath('data.progress.current_sheet', '16')
            ->assertJsonPath('data.progress.percent', 40)
            ->assertJsonMissingPath('data.failure');
        $this->getJson('/api/indices/admin/imports/'.Str::uuid())->assertNotFound();
    }

    public function test_issue_endpoint_filters_sorts_and_bounds_pagination(): void
    {
        $this->actingAsRole('admin');
        $import = StatisticalImport::factory()->create();
        StatisticalImportIssue::factory()->create([
            'import_id' => $import->id,
            'severity' => StatisticalImportIssueSeverity::Fatal,
            'code' => 'fatal_fixture',
            'sheet_name' => '16',
        ]);
        StatisticalImportIssue::factory()->create([
            'import_id' => $import->id,
            'severity' => StatisticalImportIssueSeverity::Warning,
            'code' => 'warning_fixture',
            'sheet_name' => '24',
        ]);
        StatisticalImportIssue::factory()->create([
            'import_id' => $import->id,
            'severity' => StatisticalImportIssueSeverity::Informational,
            'code' => 'informational_fixture',
            'sheet_name' => 'ignored',
        ]);

        $this->getJson("/api/indices/admin/imports/{$import->public_id}/issues?severity=fatal&code=fatal_fixture&sheet_name=16&sort=severity&direction=asc&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'fatal_fixture')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonMissingPath('data.0.import_id');
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/issues?severity=informational")
            ->assertOk()->assertJsonPath('data.0.code', 'informational_fixture');
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/issues?per_page=501")
            ->assertUnprocessable();
    }

    public function test_observations_are_bounded_eager_loaded_and_preserve_decimal_and_ag_code(): void
    {
        $this->actingAsRole('admin');
        [$import, $series] = $this->observationFixture();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?item_code=05.10.10.101.АГ&sort=item_code&direction=asc")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.series.item_code', '05.10.10.101.АГ')
            ->assertJsonPath('data.0.value', '103.0000000000')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.series.id')
            ->assertJsonMissingPath('data.0.provenance.source_file_id');
        $this->assertLessThanOrEqual(10, count(DB::getQueryLog()));
        DB::disableQueryLog();

        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?item_code=05.10.&period_from=2024-01-01&period_to=2026-12-01")
            ->assertOk()->assertJsonCount(3, 'data');
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?item_name=кухонной")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.series.item_code', '31.02.10.140')
            ->assertJsonPath('data.0.value', '106.8100000000')
            ->assertJsonPath('data.1.value', '104.9600000000')
            ->assertJsonPath('data.2.value', '99.9900000000')
            ->assertJsonPath('data.2.provenance.footnote_marker', '1)');
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?missing=true")
            ->assertOk()->assertJsonPath('data.0.missing_reason', 'ellipsis');
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?sheet_name=fixture")
            ->assertOk()->assertJsonCount(6, 'data');
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?sort=unsafe_column")
            ->assertUnprocessable();
        $this->getJson("/api/indices/admin/imports/{$import->public_id}/observations?per_page=501")
            ->assertUnprocessable();
        $this->assertCount(3, $series);
    }

    public function test_publish_moves_active_pointer_and_active_import_endpoint_is_nullable(): void
    {
        $actor = $this->actingAsRole('superadmin');
        $dataset = StatisticalDataset::factory()->create();
        $file = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $first = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $file->id,
            'attempt_no' => 1,
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);
        $second = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $file->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);

        $this->getJson("/api/indices/admin/datasets/{$dataset->public_id}/active-import")
            ->assertOk()->assertExactJson(['data' => null]);
        $this->postJson("/api/indices/admin/imports/{$first->public_id}/publish")
            ->assertOk()->assertJsonPath('data.publication.is_current', true)
            ->assertJsonPath('meta.previous_import_public_id', null)
            ->assertJsonPath('meta.superseded', false);
        $this->postJson("/api/indices/admin/imports/{$second->public_id}/publish")
            ->assertOk()->assertJsonPath('meta.previous_import_public_id', $first->public_id)
            ->assertJsonPath('meta.superseded', true);

        $this->assertSame(StatisticalImportStatus::Superseded, $first->refresh()->status);
        $this->assertSame(StatisticalImportStatus::Published, $second->refresh()->status);
        $this->assertSame($actor->id, $second->published_by_user_id);
        $this->assertSame($second->id, StatisticalDatasetActiveImport::query()->where('dataset_id', $dataset->id)->sole()->import_id);
        $this->getJson("/api/indices/admin/datasets/{$dataset->public_id}/active-import")
            ->assertOk()->assertJsonPath('data.public_id', $second->public_id);
        $this->postJson("/api/indices/admin/imports/{$second->public_id}/publish")
            ->assertConflict()->assertJsonPath('code', 'import_already_published');
        $pending = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $file->id,
            'attempt_no' => 3,
            'status' => StatisticalImportStatus::Pending,
        ]);
        $this->postJson("/api/indices/admin/imports/{$pending->public_id}/publish")
            ->assertConflict()->assertJsonPath('code', 'import_not_ready');
    }

    public function test_retry_creates_new_attempt_and_leaves_failed_original_untouched(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();
        $file = $this->sourceFileForWorkbook($dataset, $this->writeRepresentativeWorkbook());
        $failed = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $file->id,
            'attempt_no' => 1,
            'status' => StatisticalImportStatus::Failed,
            'failure_code' => 'fixture_failure',
            'failure_message' => 'Original failure.',
            'failed_at' => now(),
        ]);

        $response = $this->postJson("/api/indices/admin/imports/{$failed->public_id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.attempt_no', 2)
            ->assertJsonPath('meta.queued', true);
        $retry = StatisticalImport::query()->where('public_id', $response->json('data.public_id'))->sole();
        $this->assertSame($failed->id, $retry->retry_of_import_id);
        $this->assertSame(StatisticalImportStatus::Failed, $failed->refresh()->status);
        $this->assertSame('fixture_failure', $failed->failure_code);
        Queue::assertPushed(RunStatisticalImportJob::class, 1);

        $this->postJson("/api/indices/admin/imports/{$retry->public_id}/retry")
            ->assertConflict()->assertJsonPath('code', 'import_not_failed');
    }

    public function test_retry_rejects_inactive_source_unavailable_importer_and_later_attempt(): void
    {
        Queue::fake();
        $this->actingAsRole('admin');
        $dataset = $this->createReferenceDataset();

        $inactiveFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Superseded,
        ]);
        $inactive = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $inactiveFile->id,
            'status' => StatisticalImportStatus::Failed,
        ]);
        $this->postJson("/api/indices/admin/imports/{$inactive->public_id}/retry")
            ->assertConflict()->assertJsonPath('code', 'source_file_not_active');

        $activeFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $unavailable = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $activeFile->id,
            'attempt_no' => 1,
            'status' => StatisticalImportStatus::Failed,
            'importer_version' => '0.0.0',
        ]);
        $this->postJson("/api/indices/admin/imports/{$unavailable->public_id}/retry")
            ->assertConflict()->assertJsonPath('code', 'importer_unavailable');

        $failed = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $activeFile->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Failed,
        ]);
        StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $activeFile->id,
            'attempt_no' => 3,
            'status' => StatisticalImportStatus::ReadyForPublish,
        ]);
        $this->postJson("/api/indices/admin/imports/{$failed->public_id}/retry")
            ->assertConflict()->assertJsonPath('code', 'successful_import_already_exists');
        Queue::assertNothingPushed();
    }

    /** @return list<array{string,string}> */
    private function adminImportRequests(
        StatisticalDataset $dataset,
        StatisticalSourceFile $file,
        StatisticalImport $import,
    ): array {
        return [
            ['POST', "/api/indices/admin/source-files/{$file->public_id}/preview"],
            ['POST', "/api/indices/admin/source-files/{$file->public_id}/imports"],
            ['GET', '/api/indices/admin/imports'],
            ['GET', "/api/indices/admin/imports/{$import->public_id}"],
            ['GET', "/api/indices/admin/imports/{$import->public_id}/issues"],
            ['GET', "/api/indices/admin/imports/{$import->public_id}/observations"],
            ['POST', "/api/indices/admin/imports/{$import->public_id}/publish"],
            ['POST', "/api/indices/admin/imports/{$import->public_id}/retry"],
            ['GET', "/api/indices/admin/datasets/{$dataset->public_id}/active-import"],
        ];
    }

    /** @return array{StatisticalImport,list<StatisticalSeries>} */
    private function observationFixture(): array
    {
        $dataset = StatisticalDataset::factory()->create();
        $sourceFile = StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
        ]);
        $import = StatisticalImport::factory()->create([
            'dataset_id' => $dataset->id,
            'source_file_id' => $sourceFile->id,
            'status' => StatisticalImportStatus::Importing,
        ]);
        $indicator = StatisticalIndicator::factory()->create(['dataset_id' => $dataset->id]);
        $territory = StatisticalTerritory::factory()->create(['code' => 'RU']);
        $items = [
            StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '31.02.10.140',
                'name' => 'Наборы кухонной мебели',
                'normalized_name' => 'наборы кухонной мебели',
            ]),
            StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '05.10.10.101',
                'name' => 'Базовый товар',
                'normalized_name' => 'базовый товар',
            ]),
            StatisticalClassifierItem::factory()->create([
                'dataset_id' => $dataset->id,
                'item_code' => '05.10.10.101.АГ',
                'name' => 'Локальный товар',
                'normalized_name' => 'локальный товар',
            ]),
        ];
        $series = array_map(fn ($item) => StatisticalSeries::factory()->create([
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'classifier_item_id' => $item->id,
            'territory_id' => $territory->id,
        ]), $items);

        $this->createObservation($import, $series[0], $sourceFile, '2024-01-01', '106.8100000000');
        $this->createObservation($import, $series[0], $sourceFile, '2025-03-01', '104.9600000000');
        $this->createObservation(
            $import,
            $series[0],
            $sourceFile,
            '2026-06-01',
            '99.9900000000',
            footnoteMarker: '1)',
            raw: '99,991)',
        );
        $this->createObservation($import, $series[1], $sourceFile, '2025-01-01', '101.0000000000');
        $this->createObservation($import, $series[2], $sourceFile, '2025-03-01', '103.0000000000');
        $this->createObservation(
            $import,
            $series[1],
            $sourceFile,
            '2026-06-01',
            null,
            StatisticalObservationMissingReason::Ellipsis,
        );

        return [$import, $series];
    }

    private function createObservation(
        StatisticalImport $import,
        StatisticalSeries $series,
        StatisticalSourceFile $sourceFile,
        string $period,
        ?string $value,
        ?StatisticalObservationMissingReason $missingReason = null,
        ?string $footnoteMarker = null,
        ?string $raw = null,
    ): void {
        StatisticalObservation::factory()->create([
            'import_id' => $import->id,
            'series_id' => $series->id,
            'source_file_id' => $sourceFile->id,
            'period_start' => $period,
            'value' => $value,
            'missing_reason' => $missingReason,
            'sheet_name' => 'fixture',
            'source_row' => 10,
            'source_column' => 'C',
            'source_cell_address' => 'C10',
            'source_value_raw' => $raw ?? $value ?? '…',
            'footnote_marker' => $footnoteMarker,
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
