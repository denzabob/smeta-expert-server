<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalClassifierProvenanceFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_source_artifact_identity_is_unique_by_classifier_and_hash(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $sha256 = hash('sha256', 'same artifact bytes');

        StatisticalClassifierSourceFile::factory()->for($classifier, 'classifier')->create([
            'sha256' => $sha256,
        ]);

        $this->expectException(QueryException::class);

        StatisticalClassifierSourceFile::factory()->for($classifier, 'classifier')->create([
            'sha256' => $sha256,
            'source_page_url' => 'https://another.example.test/page',
        ]);
    }

    public function test_same_artifact_hash_is_allowed_for_different_classifiers(): void
    {
        $sha256 = hash('sha256', 'shared reference bytes');
        $first = StatisticalClassifierSourceFile::factory()->create(['sha256' => $sha256]);
        $second = StatisticalClassifierSourceFile::factory()->create(['sha256' => $sha256]);

        $this->assertNotSame($first->classifier_id, $second->classifier_id);
        $this->assertSame($first->sha256, $second->sha256);
    }

    public function test_source_file_has_typed_trust_tier_nullable_metadata_and_public_id(): void
    {
        $sourceFile = StatisticalClassifierSourceFile::factory()
            ->referenceFixture()
            ->create([
                'source_page_url' => null,
                'download_url' => null,
                'resolved_url' => null,
                'metadata_json' => null,
            ]);

        $this->assertSame(ClassifierSourceTrustTier::ReferenceFixture, $sourceFile->trust_tier);
        $this->assertNull($sourceFile->metadata_json);
        $this->assertTrue(Str::isUuid($sourceFile->public_id));
        $this->assertSame('public_id', $sourceFile->getRouteKeyName());
        $this->assertTrue($sourceFile->classifier->is($sourceFile->classifier()->firstOrFail()));
    }

    public function test_source_file_public_id_is_unique(): void
    {
        $sourceFile = StatisticalClassifierSourceFile::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalClassifierSourceFile::factory()->create([
            'public_id' => $sourceFile->public_id,
        ]);
    }

    public function test_import_must_reference_a_source_file_of_the_same_classifier(): void
    {
        $importClassifier = StatisticalClassifier::factory()->create();
        $otherSource = StatisticalClassifierSourceFile::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalClassifierImport::factory()
            ->for($importClassifier, 'classifier')
            ->for($otherSource, 'sourceFile')
            ->create();
    }

    public function test_import_attempt_is_positive_and_unique_per_source_file(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $sourceFile = StatisticalClassifierSourceFile::factory()->for($classifier, 'classifier')->create();

        StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($sourceFile, 'sourceFile')
            ->create(['attempt' => 1]);

        $this->expectException(QueryException::class);

        StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($sourceFile, 'sourceFile')
            ->create(['attempt' => 1]);
    }

    public function test_database_rejects_non_positive_import_attempt(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $sourceFile = StatisticalClassifierSourceFile::factory()->for($classifier, 'classifier')->create();

        $this->expectException(QueryException::class);

        StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($sourceFile, 'sourceFile')
            ->create(['attempt' => 0]);
    }

    public function test_import_statuses_and_parser_identity_are_typed_without_creating_version(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $sourceFile = StatisticalClassifierSourceFile::factory()->for($classifier, 'classifier')->create();

        foreach (ClassifierImportStatus::cases() as $index => $status) {
            $import = StatisticalClassifierImport::factory()
                ->for($classifier, 'classifier')
                ->for($sourceFile, 'sourceFile')
                ->create([
                    'attempt' => $index + 1,
                    'status' => $status,
                    'parser_code' => 'okpd2_fixture_parser',
                    'parser_version' => '2.0.0',
                ]);

            $this->assertSame($status, $import->status);
            $this->assertSame('okpd2_fixture_parser', $import->parser_code);
            $this->assertSame('2.0.0', $import->parser_version);
            $this->assertNull($import->version);
        }

        $this->assertSame(0, StatisticalClassifierVersion::query()->count());
    }

    public function test_valid_provenance_graph_has_expected_relations(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $sourceFile = StatisticalClassifierSourceFile::factory()->for($classifier, 'classifier')->create();
        $import = StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($sourceFile, 'sourceFile')
            ->create(['status' => ClassifierImportStatus::Ready]);
        $version = StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($import, 'classifierImport')
            ->create(['status' => ClassifierVersionStatus::Ready]);

        $this->assertTrue($classifier->sourceFiles->contains($sourceFile));
        $this->assertTrue($classifier->imports->contains($import));
        $this->assertTrue($sourceFile->imports->contains($import));
        $this->assertTrue($import->sourceFile->is($sourceFile));
        $this->assertTrue($import->version->is($version));
        $this->assertTrue($version->classifierImport->is($import));
        $this->assertTrue(Str::isUuid($import->public_id));
        $this->assertSame('public_id', $import->getRouteKeyName());
    }

    public function test_database_rejects_version_without_import_provenance(): void
    {
        $classifier = StatisticalClassifier::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('statistical_classifier_versions')->insert([
            'public_id' => (string) Str::uuid(),
            'classifier_id' => $classifier->id,
            'version_label' => 'missing-provenance',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'approved_at' => null,
            'source_published_at' => null,
            'status' => ClassifierVersionStatus::Ready->value,
            'node_count' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_rejects_version_import_from_another_classifier(): void
    {
        $versionClassifier = StatisticalClassifier::factory()->create();
        $otherImport = StatisticalClassifierImport::factory()->create();

        $this->expectException(QueryException::class);

        StatisticalClassifierVersion::factory()
            ->for($versionClassifier, 'classifier')
            ->for($otherImport, 'classifierImport')
            ->create();
    }

    public function test_one_import_cannot_create_two_versions(): void
    {
        $classifier = StatisticalClassifier::factory()->create();
        $import = StatisticalClassifierImport::factory()->for($classifier, 'classifier')->create();

        StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($import, 'classifierImport')
            ->create(['version_label' => 'first-version']);

        $this->expectException(QueryException::class);

        StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($import, 'classifierImport')
            ->create(['version_label' => 'second-version']);
    }

    public function test_existing_version_label_uniqueness_and_empty_active_pointer_are_preserved(): void
    {
        $classifier = StatisticalClassifier::factory()->create();

        StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->create(['version_label' => '145/2026']);

        $this->assertSame(0, DB::table('statistical_classifier_active_versions')->count());

        $this->expectException(QueryException::class);

        StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->create(['version_label' => '145/2026']);
    }
}
