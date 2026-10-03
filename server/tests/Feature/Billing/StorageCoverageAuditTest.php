<?php

namespace Tests\Feature\Billing;

use App\Models\EvidenceRecord;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\GenericEvidenceAsset;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Models\User;
use App\Services\Storage\ObjectStorage;
use App\Services\Storage\StorageUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageCoverageAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_clean_expert_registry_coverage_passes(): void
    {
        $project = ExpertProject::create([
            'user_id' => $this->user->id,
            'name' => 'Coverage expert',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $path = 'expert/' . $project->public_id . '/materials/clean.pdf';
        $this->register($path, 'expert');
        ExpertProjectMaterial::create($this->expertAttributes($project, $path));

        $this->assertCoveragePasses();
    }

    public function test_expert_business_file_missing_registry_is_reported(): void
    {
        $project = ExpertProject::create([
            'user_id' => $this->user->id,
            'name' => 'Coverage expert missing',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        $path = 'expert/' . $project->public_id . '/materials/missing.pdf';
        ExpertProjectMaterial::create($this->expertAttributes($project, $path));

        $this->assertCoverageFailureContains('UNREGISTERED_PERSISTENT_FILE');
    }

    public function test_clean_smeta_registry_coverage_passes(): void
    {
        $this->createEvidenceAsset('smeta/evidence-records/clean.pdf', register: true);

        $this->assertCoveragePasses();
    }

    public function test_smeta_business_file_missing_registry_is_reported(): void
    {
        $this->createEvidenceAsset('smeta/evidence-records/missing.pdf', register: false);

        $this->assertCoverageFailureContains('UNREGISTERED_PERSISTENT_FILE');
    }

    public function test_active_registry_file_without_business_link_is_reported_as_orphan(): void
    {
        $this->register('smeta/evidence-records/orphan.pdf', 'smeta');

        $this->assertCoverageFailureContains('ORPHAN_STORAGE_FILE');
    }

    public function test_pending_delete_registry_file_is_not_reported_as_orphan(): void
    {
        $this->register('smeta/evidence-records/pending.pdf', 'smeta');
        DB::table('storage_files')->where('path', 'smeta/evidence-records/pending.pdf')->update([
            'status' => 'deleting',
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        app(StorageUsageService::class)->recalculate($this->user->id);

        $this->assertCoveragePasses();
    }

    public function test_invalid_expert_disk_and_smeta_path_fail_coverage(): void
    {
        $project = ExpertProject::create([
            'user_id' => $this->user->id,
            'name' => 'Coverage invalid',
            'domain' => 'other',
            'work_type' => 'other',
        ]);
        ExpertProjectMaterial::create($this->expertAttributes(
            $project,
            'expert/' . $project->public_id . '/materials/local.pdf',
            'local',
        ));
        ExpertProjectMaterial::create($this->expertAttributes(
            $project,
            'smeta/evidence-records/wrong-module.pdf',
        ));

        $result = $this->artisan('storage:usage-audit', ['--all' => true])
            ->expectsOutputToContain('INVALID_PERSISTENT_DISK')
            ->expectsOutputToContain('INVALID_PERSISTENT_PATH');
        $result->assertExitCode(1);
    }

    public function test_parser_snapshot_is_excluded_from_coverage(): void
    {
        $project = Project::create([
            'user_id' => $this->user->id,
            'number' => 'COVERAGE-PARSER',
            'expert_name' => 'Coverage parser',
            'address' => 'Fixture',
        ]);
        $snapshot = json_encode([
            'screenshot_path' => 'smeta/screenshots/parser/system.png',
            'storage_disk' => ObjectStorage::DISK,
        ], JSON_THROW_ON_ERROR);
        ProjectRevision::create([
            'project_id' => $project->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
        ]);

        $this->assertCoveragePasses();
    }

    public function test_legacy_public_revision_locator_is_historical_and_does_not_require_registry(): void
    {
        $project = Project::create([
            'user_id' => $this->user->id,
            'number' => 'COVERAGE-HISTORICAL',
            'expert_name' => 'Coverage historical',
            'address' => 'Fixture',
        ]);
        $snapshot = json_encode([
            'price_justifications' => [[
                'screenshot_path' => 'screenshots/chrome/generic/2026/04/legacy.jpg',
                'storage_disk' => 'public',
            ]],
        ], JSON_THROW_ON_ERROR);
        ProjectRevision::create([
            'project_id' => $project->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
        ]);

        $this->artisan('storage:usage-audit', ['--all' => true])->assertExitCode(0);
        $this->assertSame(1, app(\App\Services\Storage\StorageCoverageAudit::class)->audit()['historical']);
    }

    public function test_legacy_public_revision_locator_is_skipped_by_backfill(): void
    {
        $project = Project::create([
            'user_id' => $this->user->id,
            'number' => 'BACKFILL-HISTORICAL',
            'expert_name' => 'Backfill historical',
            'address' => 'Fixture',
        ]);
        $snapshot = json_encode([
            'screenshot_path' => 'screenshots/chrome/generic/2026/04/legacy-backfill.jpg',
            'storage_disk' => 'public',
        ], JSON_THROW_ON_ERROR);
        ProjectRevision::create([
            'project_id' => $project->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
        ]);

        $this->artisan('storage:backfill-registry', ['--dry-run' => true])->assertSuccessful();
    }

    public function test_canonical_s1_revision_locator_without_registry_is_reported(): void
    {
        $project = Project::create([
            'user_id' => $this->user->id,
            'number' => 'COVERAGE-REVISION-S1',
            'expert_name' => 'Coverage revision S1',
            'address' => 'Fixture',
        ]);
        $snapshot = json_encode([
            'price_justifications' => [[
                'screenshot_path' => 'smeta/screenshots/chrome/generic/1/modern.jpg',
                'storage_disk' => ObjectStorage::DISK,
            ]],
        ], JSON_THROW_ON_ERROR);
        ProjectRevision::create([
            'project_id' => $project->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
        ]);

        $this->assertCoverageFailureContains('UNREGISTERED_PERSISTENT_FILE');
    }

    public function test_noncanonical_explicit_s1_revision_locator_is_not_hidden_as_historical(): void
    {
        $project = Project::create([
            'user_id' => $this->user->id,
            'number' => 'COVERAGE-REVISION-S1-PATH',
            'expert_name' => 'Coverage revision S1 path',
            'address' => 'Fixture',
        ]);
        $snapshot = json_encode([
            'price_justifications' => [[
                'screenshot_path' => 'screenshots/chrome/generic/1/incorrect-modern.jpg',
                'storage_disk' => ObjectStorage::DISK,
            ]],
        ], JSON_THROW_ON_ERROR);
        ProjectRevision::create([
            'project_id' => $project->id,
            'number' => 1,
            'status' => 'locked',
            'snapshot_json' => $snapshot,
            'snapshot_hash' => hash('sha256', $snapshot),
        ]);

        $result = $this->artisan('storage:usage-audit', ['--all' => true])
            ->expectsOutputToContain('INVALID_PERSISTENT_PATH');
        $result->assertExitCode(1);
        $this->assertSame(0, app(\App\Services\Storage\StorageCoverageAudit::class)->audit()['historical']);
    }

    public function test_multiple_business_links_count_one_physical_object(): void
    {
        $path = 'smeta/evidence-records/shared.pdf';
        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => 'operation',
            'source_type' => 'document',
            'capture_method' => 'file_upload',
            'verification_status' => 'pending',
            'created_by' => $this->user->id,
        ]);
        $this->register($path, 'smeta');
        foreach (['screenshot', 'document'] as $assetType) {
            GenericEvidenceAsset::create([
                'uuid' => (string) Str::uuid(),
                'evidence_record_id' => $record->id,
                'asset_type' => $assetType,
                'storage_disk' => ObjectStorage::DISK,
                'file_path' => $path,
                'file_size' => 3,
            ]);
        }

        $this->assertCoveragePasses();
        $this->assertDatabaseCount('storage_files', 1);
        $this->assertDatabaseCount('storage_file_links', 2);
    }

    private function assertCoveragePasses(): void
    {
        $this->artisan('storage:usage-audit', ['--all' => true])
            ->expectsOutput('Coverage: unregistered persistent: 0; orphan registry: 0; invalid locators: 0')
            ->assertExitCode(0);
    }

    private function assertCoverageFailureContains(string $code): void
    {
        $this->artisan('storage:usage-audit', ['--all' => true])
            ->expectsOutputToContain($code)
            ->assertExitCode(1);
    }

    private function register(string $path, string $module): int
    {
        return app(StorageUsageService::class)->register($this->user->id, $module, [
            'disk' => ObjectStorage::DISK,
            'path' => $path,
            'purpose' => 'coverage-fixture',
            'size_bytes' => 3,
            'mime_type' => 'application/pdf',
            'original_filename' => 'fixture.pdf',
            'billable' => true,
        ]);
    }

    private function createEvidenceAsset(string $path, bool $register): GenericEvidenceAsset
    {
        $record = EvidenceRecord::create([
            'uuid' => (string) Str::uuid(),
            'cost_component' => 'operation',
            'source_type' => 'document',
            'capture_method' => 'file_upload',
            'verification_status' => 'pending',
            'created_by' => $this->user->id,
        ]);
        if ($register) {
            $this->register($path, 'smeta');
        }

        return GenericEvidenceAsset::create([
            'uuid' => (string) Str::uuid(),
            'evidence_record_id' => $record->id,
            'asset_type' => 'document',
            'storage_disk' => ObjectStorage::DISK,
            'file_path' => $path,
            'file_size' => 3,
        ]);
    }

    /** @return array<string,mixed> */
    private function expertAttributes(ExpertProject $project, string $path, string $disk = 's1'): array
    {
        return [
            'expert_project_id' => $project->id,
            'uploaded_by' => $this->user->id,
            'original_name' => basename($path),
            'storage_disk' => $disk,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 3,
            'category' => 'document',
            'status' => 'uploaded',
        ];
    }
}
