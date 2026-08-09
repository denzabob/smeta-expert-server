<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Sources\StatisticalSource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\PriceIndices\Support\BuildsSyntheticXlsx;
use Tests\TestCase;

class PriceIndicesAdminApiTest extends TestCase
{
    use BuildsSyntheticXlsx;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('price_indices.enabled', true);
        Config::set('price_indices.admin_only', true);
        Config::set('price_indices.source_files.storage_disk', 'price_indices_api_test');
        Storage::fake('price_indices_api_test');
    }

    protected function tearDown(): void
    {
        $this->forgetSyntheticXlsxFiles();
        parent::tearDown();
    }

    public function test_admin_routes_enforce_exact_role_access(): void
    {
        $this->getJson('/api/indices/admin/datasets')->assertUnauthorized();

        foreach ([['user', 200], ['auditor', 201], ['user', 1]] as [$role, $id]) {
            $this->actingAsRole($role, $id);
            $this->getJson('/api/indices/admin/datasets')->assertForbidden();
        }

        foreach (['admin', 'superadmin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/indices/admin/datasets')->assertOk();
        }
    }

    public function test_dataset_api_lists_creates_updates_and_hides_numeric_id(): void
    {
        $this->actingAsRole('admin');

        $response = $this->postJson('/api/indices/admin/datasets', $this->datasetPayload());
        $response->assertCreated()
            ->assertJsonPath('data.code', 'api_dataset')
            ->assertJsonMissingPath('data.id');
        $publicId = $response->json('data.public_id');

        $this->getJson('/api/indices/admin/datasets?provider_code=rosstat&is_enabled=true&sort=name&direction=asc&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonMissingPath('data.0.id');

        $this->putJson("/api/indices/admin/datasets/{$publicId}", ['name' => 'Updated dataset'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated dataset');

        $this->getJson("/api/indices/admin/datasets/{$publicId}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $publicId);
    }

    public function test_dataset_code_becomes_immutable_after_first_source_file(): void
    {
        $this->actingAsRole('admin');
        $dataset = StatisticalDataset::factory()->create(['code' => 'immutable_dataset']);
        StatisticalSourceFile::factory()->for($dataset, 'dataset')->create();

        $this->putJson("/api/indices/admin/datasets/{$dataset->public_id}", [
            'code' => 'renamed_dataset',
        ])->assertConflict()
            ->assertJsonPath('code', 'immutable_dataset_code');

        $this->assertSame('immutable_dataset', $dataset->refresh()->code);
    }

    public function test_source_api_uses_dataset_public_id_and_validates_https_templates(): void
    {
        $this->actingAsRole('admin');
        $dataset = StatisticalDataset::factory()->create();
        $payload = [
            'dataset_public_id' => $dataset->public_id,
            'code' => 'rosstat_source',
            'name' => 'Росстат XLSX',
            'source_page_url' => 'https://rosstat.gov.ru/statistics/price',
            'download_url_template' => 'https://rosstat.gov.ru/files/{year}/{month2}.xlsx',
            'filename_template' => 'indices_{year}_{month2}.xlsx',
            'http_method' => 'get',
            'is_enabled' => true,
            'automatic_check_enabled' => false,
            'settings_json' => ['mode' => 'manual'],
        ];

        $response = $this->postJson('/api/indices/admin/sources', $payload)
            ->assertCreated()
            ->assertJsonPath('data.dataset.public_id', $dataset->public_id)
            ->assertJsonPath('data.http_method', 'GET')
            ->assertJsonMissingPath('data.id');
        $sourcePublicId = $response->json('data.public_id');

        $this->getJson("/api/indices/admin/sources?dataset={$dataset->public_id}&per_page=5")
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $sourcePublicId);

        $this->putJson("/api/indices/admin/sources/{$sourcePublicId}", ['name' => 'Updated source'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated source');

        $payload['code'] = 'invalid_template';
        $payload['download_url_template'] = 'https://example.test/{unknown}.xlsx';
        $this->postJson('/api/indices/admin/sources', $payload)->assertUnprocessable();

        $payload['code'] = 'http_source';
        $payload['download_url_template'] = 'https://example.test/{year}.xlsx';
        $payload['source_page_url'] = 'http://example.test/source';
        $this->postJson('/api/indices/admin/sources', $payload)->assertUnprocessable();
    }

    public function test_valid_upload_is_created_privately_and_resource_hides_internal_fields(): void
    {
        $actor = $this->actingAsRole('admin');
        $dataset = StatisticalDataset::factory()->create(['code' => 'upload_dataset']);

        $response = $this->upload($dataset, $this->makeSyntheticXlsx(), 'Original Report.xlsx');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.dataset.public_id', $dataset->public_id)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.stored_path');
        $file = StatisticalSourceFile::query()->where('public_id', $response->json('data.public_id'))->sole();

        $this->assertSame($actor->id, $file->uploaded_by_user_id);
        $this->assertSame('Original Report.xlsx', $file->original_filename);
        $this->assertStringNotContainsString('Original Report.xlsx', $file->stored_path);
        $this->assertStringStartsWith('price-indices/source-files/upload_dataset/2026/08/', $file->stored_path);
        Storage::disk('price_indices_api_test')->assertExists($file->stored_path);
        $this->assertSame([], Storage::disk('price_indices_api_test')->allFiles('price-indices/tmp'));
    }

    public function test_invalid_large_mismatched_and_duplicate_upload_errors_are_controlled(): void
    {
        $this->actingAsRole('admin');
        $dataset = StatisticalDataset::factory()->create();

        $corrupt = tempnam(sys_get_temp_dir(), 'price_indices_corrupt_api_');
        file_put_contents($corrupt, "PK\x03\x04corrupt");
        $this->syntheticXlsxPaths[] = $corrupt;
        $this->upload($dataset, $corrupt)->assertUnprocessable()->assertJsonPath('code', 'invalid_zip');
        $this->assertSame(0, $dataset->sourceFiles()->count());

        $large = UploadedFile::fake()->create(
            'large.xlsx',
            65_537,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
        $this->post('/api/indices/admin/source-files/upload', [
            'dataset_public_id' => $dataset->public_id,
            'reporting_year' => 2026,
            'reporting_month' => 8,
            'file' => $large,
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $otherSource = StatisticalSource::factory()->create();
        $this->upload($dataset, $this->makeSyntheticXlsx(), source: $otherSource)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'source_dataset_mismatch');

        $fixture = $this->makeSyntheticXlsx(['xl/unique.xml' => 'same']);
        $first = $this->upload($dataset, $fixture)->assertCreated();
        $duplicate = $this->upload($dataset, $fixture)
            ->assertConflict()
            ->assertJsonPath('code', 'duplicate_file')
            ->assertJsonPath('existing_file.public_id', $first->json('data.public_id'));
        $this->assertArrayNotHasKey('stored_path', $duplicate->json('existing_file'));
    }

    public function test_source_file_filters_uuid_binding_and_unknown_uuid(): void
    {
        $this->actingAsRole('admin');
        $dataset = StatisticalDataset::factory()->create();
        $file = StatisticalSourceFile::factory()->for($dataset, 'dataset')->create([
            'reporting_year' => 2026,
            'reporting_month' => 8,
        ]);

        $this->getJson("/api/indices/admin/source-files?dataset={$dataset->public_id}&status=pending_review&reporting_year=2026&reporting_month=8&per_page=5")
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $file->public_id)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.stored_path');

        $this->getJson("/api/indices/admin/source-files/{$file->public_id}")
            ->assertOk()
            ->assertJsonPath('data.public_id', $file->public_id);

        $this->getJson('/api/indices/admin/source-files/'.Str::uuid())->assertNotFound();
    }

    public function test_approve_reject_activate_supersede_and_download_use_existing_services(): void
    {
        $this->actingAsRole('superadmin');
        $dataset = StatisticalDataset::factory()->create(['code' => 'lifecycle_api']);
        $firstFixture = $this->makeSyntheticXlsx(['xl/first.xml' => 'first']);
        $firstResponse = $this->upload($dataset, $firstFixture, 'first.xlsx')->assertCreated();
        $firstPublicId = $firstResponse->json('data.public_id');
        $firstFile = StatisticalSourceFile::query()->where('public_id', $firstPublicId)->sole();

        $this->postJson("/api/indices/admin/source-files/{$firstPublicId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/indices/admin/source-files/{$firstPublicId}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_lifecycle');
        $this->postJson("/api/indices/admin/source-files/{$firstPublicId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.active', true);

        $download = $this->get("/api/indices/admin/source-files/{$firstPublicId}/download");
        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        $this->assertStringContainsString('first.xlsx', (string) $download->headers->get('content-disposition'));

        $secondResponse = $this->upload(
            $dataset,
            $this->makeSyntheticXlsx(['xl/second.xml' => 'second']),
            'second.xlsx'
        )->assertCreated();
        $secondPublicId = $secondResponse->json('data.public_id');
        $this->postJson("/api/indices/admin/source-files/{$secondPublicId}/approve")->assertOk();
        $this->postJson("/api/indices/admin/source-files/{$secondPublicId}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.supersedes_public_id', $firstPublicId);
        $this->assertSame(SourceFileStatus::Superseded, $firstFile->refresh()->status);

        $rejectedResponse = $this->upload(
            $dataset,
            $this->makeSyntheticXlsx(['xl/rejected.xml' => 'rejected']),
            'rejected.xlsx',
            reportingMonth: 9
        )->assertCreated();
        $rejectedPublicId = $rejectedResponse->json('data.public_id');
        $rejected = StatisticalSourceFile::query()->where('public_id', $rejectedPublicId)->sole();
        $this->postJson("/api/indices/admin/source-files/{$rejectedPublicId}/reject", [
            'reason' => 'Wrong source period',
        ])->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Wrong source period');
        Storage::disk('price_indices_api_test')->assertExists($rejected->stored_path);

        Storage::disk('price_indices_api_test')->delete($rejected->stored_path);
        $this->getJson("/api/indices/admin/source-files/{$rejectedPublicId}/download")
            ->assertNotFound()
            ->assertJsonMissingPath('stored_path');
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetPayload(): array
    {
        return [
            'code' => 'api_dataset',
            'name' => 'API Dataset',
            'provider_code' => 'rosstat',
            'provider_name' => 'Росстат',
            'data_kind' => 'price_index',
            'frequency' => 'monthly',
            'classifier_code' => 'okpd2_based',
            'territory_scope' => 'russian_federation',
            'is_enabled' => true,
            'automatic_check_enabled' => false,
        ];
    }

    private function upload(
        StatisticalDataset $dataset,
        string $fixturePath,
        string $originalName = 'indices.xlsx',
        ?StatisticalSource $source = null,
        int $reportingMonth = 8,
    ): \Illuminate\Testing\TestResponse {
        $file = new UploadedFile(
            $fixturePath,
            $originalName,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );

        return $this->post('/api/indices/admin/source-files/upload', [
            'dataset_public_id' => $dataset->public_id,
            'source_public_id' => $source?->public_id,
            'reporting_year' => 2026,
            'reporting_month' => $reportingMonth,
            'comment' => 'API test upload',
            'file' => $file,
        ], ['Accept' => 'application/json']);
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
