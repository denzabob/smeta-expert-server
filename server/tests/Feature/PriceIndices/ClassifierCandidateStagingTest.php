<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ClassifierParserIdentity;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationSummary;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Application\Services\FindEquivalentReadyClassifierImport;
use App\Domain\PriceIndices\Application\Services\ParseOkpd2ClassifierArtifact;
use App\Domain\PriceIndices\Application\Services\StageTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ClassifierCandidateStagingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_source_absent_or_wrong_sha_creates_no_import_attempt(): void
    {
        $descriptor = $this->descriptor();
        StatisticalClassifier::factory()->create(['code' => $descriptor->classifierCode]);

        $this->assertStageError('source_artifact_not_available');
        $this->assertSame(0, StatisticalClassifierImport::query()->count());

        $classifier = StatisticalClassifier::query()->where('code', 'okpd2')->firstOrFail();
        StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create(['sha256' => hash('sha256', 'wrong artifact')]);

        $this->assertStageError('source_artifact_not_available');
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
    }

    public function test_reference_fixture_source_is_rejected_before_integrity_or_import(): void
    {
        $this->createTrustedSource(ClassifierSourceTrustTier::ReferenceFixture);
        $this->assertStageError('source_artifact_not_trusted');
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
    }

    public function test_operator_upload_source_is_rejected_before_attestation_policy_exists(): void
    {
        $this->createTrustedSource(ClassifierSourceTrustTier::OperatorOfficialUpload);
        $this->assertStageError('source_artifact_not_trusted');
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
    }

    public function test_corrupted_stored_artifact_is_a_controlled_failure_without_import(): void
    {
        $this->createTrustedSource();
        $storage = Mockery::mock(ClassifierArtifactStorage::class);
        $storage->shouldReceive('verify')->once()->andThrow(new ClassifierAcquisitionException(
            'storage_integrity_failure',
            'C:\\private\\classifier.zip SELECT * FROM secrets <xml> stack trace',
        ));
        $this->app->instance(ClassifierArtifactStorage::class, $storage);

        $this->assertStageError('source_artifact_integrity_failure');
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
    }

    public function test_official_candidate_stages_to_ready_without_versions_nodes_or_active_pointer(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        $this->mockIntegrity(1);
        $this->mockParser($this->snapshot(), 1);

        $result = app(StageTrustedClassifierCandidate::class)->stage('okpd2_145_2026');
        $import = StatisticalClassifierImport::query()->sole();

        $this->assertFalse($result->reused);
        $this->assertSame('ready', $result->status);
        $this->assertSame(1, $result->attempt);
        $this->assertSame($classifier->public_id, $result->classifierPublicId);
        $this->assertSame($source->public_id, $result->sourcePublicId);
        $this->assertSame($import->public_id, $result->importPublicId);
        $this->assertSame(20_982, $result->metrics['total_nodes_count']);
        $this->assertSame(ClassifierImportStatus::Ready, $import->status);
        $this->assertNotNull($import->started_at);
        $this->assertNotNull($import->finished_at);
        $this->assertSame(20_982, $import->nodes_parsed);
        $this->assertSame(21, $import->sections_count);
        $this->assertSame(0, $import->validation_errors_count);
        $this->assertSame(0, $import->validation_warnings_count);
        $this->assertSame($this->descriptor()->fingerprint(), $import->validation_summary_json['candidate_fingerprint']);
        $this->assertArrayNotHasKey('nodes', $import->validation_summary_json);
        $this->assertArrayNotHasKey('snapshot', $import->validation_summary_json);
        $this->assertNegativePersistenceAssertions();

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($source->storage_path, $encoded);
        $this->assertStringNotContainsString('classifier_id', $encoded);
        $this->assertStringNotContainsString('source_file_id', $encoded);
    }

    public function test_equivalent_ready_import_is_reused_parser_is_not_called_again_and_integrity_is_reverified(): void
    {
        $this->createTrustedSource();
        $this->mockIntegrity(2);
        $this->mockParser($this->snapshot(), 1);
        $service = app(StageTrustedClassifierCandidate::class);

        $first = $service->stage('okpd2_145_2026');
        $second = $service->stage('okpd2_145_2026');

        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);
        $this->assertSame($first->importPublicId, $second->importPublicId);
        $this->assertSame(1, StatisticalClassifierImport::query()->count());
        $this->assertNegativePersistenceAssertions();
    }

    public function test_failed_import_is_never_reused_and_next_attempt_is_created(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($source, 'sourceFile')
            ->create([
                'attempt' => 1,
                'status' => ClassifierImportStatus::Failed,
                'parser_code' => $this->descriptor()->parserCode,
                'parser_version' => '1',
                'validation_summary_json' => $this->summary(),
            ]);
        $this->mockIntegrity(1);
        $this->mockParser($this->snapshot(), 1);

        $result = app(StageTrustedClassifierCandidate::class)->stage('okpd2_145_2026');

        $this->assertFalse($result->reused);
        $this->assertSame(2, $result->attempt);
        $this->assertSame(2, StatisticalClassifierImport::query()->count());
        $this->assertSame(1, StatisticalClassifierImport::query()->where('status', ClassifierImportStatus::Ready)->count());
    }

    public function test_parser_failure_is_persisted_with_whitelisted_safe_error_only(): void
    {
        $this->createTrustedSource();
        $this->mockIntegrity(1);
        $parser = Mockery::mock(ParseOkpd2ClassifierArtifact::class);
        $parser->shouldReceive('identity')->once()->andReturn(new ClassifierParserIdentity('okpd2_rosstat_docx', 1));
        $parser->shouldReceive('parse')->once()->andThrow(ClassifierParserException::fatal(
            'synthetic_parser_failure',
            'C:\\private\\artifact.zip SELECT * FROM secrets <xml> stack trace',
        ));
        $this->app->instance(ParseOkpd2ClassifierArtifact::class, $parser);

        $this->assertStageError('synthetic_parser_failure');
        $import = StatisticalClassifierImport::query()->sole();
        $encoded = strtolower(json_encode($import->error_json, JSON_THROW_ON_ERROR));

        $this->assertSame(ClassifierImportStatus::Failed, $import->status);
        $this->assertSame(['error_code', 'safe_message', 'stage', 'context'], array_keys($import->error_json));
        $this->assertSame('parsing', $import->error_json['stage']);
        $this->assertStringNotContainsString('private', $encoded);
        $this->assertStringNotContainsString('select ', $encoded);
        $this->assertStringNotContainsString('<xml>', $encoded);
        $this->assertStringNotContainsString('stack trace', $encoded);
        $this->assertNegativePersistenceAssertions();
    }

    public function test_candidate_validation_failure_marks_validating_import_failed(): void
    {
        $this->createTrustedSource();
        $this->mockIntegrity(1);
        $this->mockParser($this->snapshot(['totalNodesCount' => 20_981]), 1);

        $this->assertStageError('candidate_total_nodes_count_mismatch');
        $import = StatisticalClassifierImport::query()->sole();

        $this->assertSame(ClassifierImportStatus::Failed, $import->status);
        $this->assertSame('validating', $import->error_json['stage']);
        $this->assertSame(1, $import->validation_errors_count);
        $this->assertNegativePersistenceAssertions();
    }

    public function test_matching_existing_version_is_reused_without_new_import(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        $import = $this->readyImport($classifier, $source);
        $version = StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($import, 'classifierImport')
            ->create([
                'version_label' => '145/2026',
                'effective_from' => '2026-07-06',
            ]);
        $this->mockIntegrity(1);
        $this->mockParserNever();

        $result = app(StageTrustedClassifierCandidate::class)->stage('okpd2_145_2026');

        $this->assertTrue($result->reused);
        $this->assertSame($import->public_id, $result->importPublicId);
        $this->assertSame($version->public_id, $result->versionPublicId);
        $this->assertSame(1, StatisticalClassifierImport::query()->count());
    }

    public function test_existing_version_with_different_source_conflicts_without_overwrite(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        $otherSource = StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create(['sha256' => hash('sha256', 'other source')]);
        $import = $this->readyImport($classifier, $otherSource);
        $this->createVersion($classifier, $import);
        $this->mockIntegrity(1);

        $this->assertStageError('candidate_version_conflict');
        $this->assertSame($otherSource->id, $import->fresh()->source_file_id);
        $this->assertSame(1, StatisticalClassifierImport::query()->count());
        $this->assertSame($source->id, StatisticalClassifierSourceFile::query()->where('sha256', $this->descriptor()->sourceSha256)->value('id'));
    }

    public function test_existing_version_with_different_parser_conflicts_without_overwrite(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        $import = $this->readyImport($classifier, $source, ['parser_code' => 'other_parser']);
        $this->createVersion($classifier, $import);
        $this->mockIntegrity(1);

        $this->assertStageError('candidate_version_conflict');
        $this->assertSame('other_parser', $import->fresh()->parser_code);
        $this->assertSame(1, StatisticalClassifierImport::query()->count());
    }

    public function test_existing_version_with_conflicting_candidate_fingerprint_conflicts_without_overwrite(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        $summary = $this->summary();
        $summary['candidate_fingerprint'] = str_repeat('0', 64);
        $import = $this->readyImport($classifier, $source, ['validation_summary_json' => $summary]);
        $this->createVersion($classifier, $import);
        $this->mockIntegrity(1);

        $this->assertStageError('candidate_version_conflict');
        $this->assertSame(str_repeat('0', 64), $import->fresh()->validation_summary_json['candidate_fingerprint']);
        $this->assertSame(1, StatisticalClassifierImport::query()->count());
    }

    public function test_ready_race_recheck_returns_winner_and_does_not_create_second_ready_success(): void
    {
        [$classifier, $source] = $this->createTrustedSource();
        $winner = $this->readyImport($classifier, $source);
        $finder = Mockery::mock(FindEquivalentReadyClassifierImport::class);
        $finder->shouldReceive('find')->twice()->andReturn(null, $winner);
        $this->app->instance(FindEquivalentReadyClassifierImport::class, $finder);
        $this->mockIntegrity(1);
        $this->mockParser($this->snapshot(), 1);

        $result = app(StageTrustedClassifierCandidate::class)->stage('okpd2_145_2026');
        $loser = StatisticalClassifierImport::query()->where('id', '!=', $winner->id)->sole();

        $this->assertTrue($result->reused);
        $this->assertSame($winner->public_id, $result->importPublicId);
        $this->assertSame(ClassifierImportStatus::Failed, $loser->status);
        $this->assertSame('equivalent_ready_import_won_race', $loser->error_json['error_code']);
        $this->assertSame(1, StatisticalClassifierImport::query()->where('status', ClassifierImportStatus::Ready)->count());
        $this->assertSame(1, StatisticalClassifierImport::query()->where('status', ClassifierImportStatus::Failed)->count());
    }

    /** @return array{StatisticalClassifier, StatisticalClassifierSourceFile} */
    private function createTrustedSource(
        ClassifierSourceTrustTier $trustTier = ClassifierSourceTrustTier::OfficialAuthoritative,
    ): array {
        $descriptor = $this->descriptor();
        $classifier = StatisticalClassifier::factory()->create(['code' => $descriptor->classifierCode]);
        $source = StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create([
                'trust_tier' => $trustTier,
                'sha256' => $descriptor->sourceSha256,
                'storage_disk' => 'price_indices_classifier_artifacts',
                'storage_path' => "classifiers/okpd2/artifacts/{$descriptor->sourceSha256}.zip",
                'size_bytes' => 123_456,
            ]);

        return [$classifier, $source];
    }

    private function mockIntegrity(int $times): void
    {
        $storage = Mockery::mock(ClassifierArtifactStorage::class);
        $storage->shouldReceive('verify')->times($times)->andReturnNull();
        $this->app->instance(ClassifierArtifactStorage::class, $storage);
    }

    private function mockParser(ParsedClassifierSnapshot $snapshot, int $times): void
    {
        $parser = Mockery::mock(ParseOkpd2ClassifierArtifact::class);
        $parser->shouldReceive('identity')->times($times)->andReturn(new ClassifierParserIdentity('okpd2_rosstat_docx', 1));
        $parser->shouldReceive('parse')->times($times)->andReturn($snapshot);
        $this->app->instance(ParseOkpd2ClassifierArtifact::class, $parser);
    }

    private function mockParserNever(): void
    {
        $parser = Mockery::mock(ParseOkpd2ClassifierArtifact::class);
        $parser->shouldNotReceive('identity');
        $parser->shouldNotReceive('parse');
        $this->app->instance(ParseOkpd2ClassifierArtifact::class, $parser);
    }

    /** @param array<string, mixed> $attributes */
    private function readyImport(
        StatisticalClassifier $classifier,
        StatisticalClassifierSourceFile $source,
        array $attributes = [],
    ): StatisticalClassifierImport {
        return StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($source, 'sourceFile')
            ->create([
                'attempt' => 1,
                'status' => ClassifierImportStatus::Ready,
                'parser_code' => $this->descriptor()->parserCode,
                'parser_version' => '1',
                'started_at' => now(),
                'finished_at' => now(),
                'nodes_parsed' => 20_982,
                'sections_count' => 21,
                'validation_summary_json' => $this->summary(),
                ...$attributes,
            ]);
    }

    private function createVersion(
        StatisticalClassifier $classifier,
        StatisticalClassifierImport $import,
    ): StatisticalClassifierVersion {
        return StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($import, 'classifierImport')
            ->create([
                'version_label' => '145/2026',
                'effective_from' => '2026-07-06',
            ]);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $descriptor = $this->descriptor();

        return [
            'candidate_key' => $descriptor->candidateKey,
            'candidate_fingerprint' => $descriptor->fingerprint(),
            'version_label' => $descriptor->versionLabel,
            'effective_from' => $descriptor->effectiveFrom,
            'source' => ['sha256' => $descriptor->sourceSha256],
            'parser' => ['code' => $descriptor->parserCode, 'version' => $descriptor->parserVersion],
            'metrics' => [
                'sections_count' => 21,
                'digital_nodes_count' => 20_961,
                'total_nodes_count' => 20_982,
                'warnings_count' => 0,
            ],
            'level_counts' => $descriptor->expectedLevelCounts,
            'notes_count' => 1_321,
        ];
    }

    /** @param array<string, int|string> $overrides */
    private function snapshot(array $overrides = []): ParsedClassifierSnapshot
    {
        $descriptor = $this->descriptor();
        $nodes = [
            $this->node('31', null, ClassifierSemanticLevel::ClassLevel),
            $this->node('31.0', '31', ClassifierSemanticLevel::Subclass),
            $this->node('31.02', '31.0', ClassifierSemanticLevel::Group),
            $this->node('31.02.1', '31.02', ClassifierSemanticLevel::Subgroup),
            $this->node('31.02.10', '31.02.1', ClassifierSemanticLevel::Type),
            new ParsedClassifierNode(
                code: '31.02.10.140',
                name: $descriptor->controlNodeName,
                normalizedName: 'наборы кухонной мебели',
                semanticLevel: ClassifierSemanticLevel::Category,
                formalDepth: 6,
                sourceOrder: 6,
                parentCode: '31.02.10',
            ),
        ];
        $summary = new ClassifierValidationSummary(
            fatalErrors: [],
            warnings: [],
            metrics: [
                'notes_count' => 1_321,
                'level_counts' => $descriptor->expectedLevelCounts,
            ],
        );

        return new ParsedClassifierSnapshot(
            parserCode: 'okpd2_rosstat_docx',
            parserVersion: 1,
            sectionsCount: 21,
            digitalNodesCount: 20_961,
            totalNodesCount: (int) ($overrides['totalNodesCount'] ?? 20_982),
            nodes: $nodes,
            warnings: [],
            validationSummary: $summary,
        );
    }

    private function node(
        string $code,
        ?string $parentCode,
        ClassifierSemanticLevel $level,
    ): ParsedClassifierNode {
        return new ParsedClassifierNode(
            code: $code,
            name: "Node {$code}",
            normalizedName: "node {$code}",
            semanticLevel: $level,
            formalDepth: 1,
            sourceOrder: 1,
            parentCode: $parentCode,
        );
    }

    private function descriptor(): TrustedClassifierCandidateDescriptor
    {
        return app(TrustedClassifierCandidateRegistry::class)->get('okpd2_145_2026');
    }

    private function assertStageError(string $expectedCode): void
    {
        try {
            app(StageTrustedClassifierCandidate::class)->stage('okpd2_145_2026');
            $this->fail("Staging error [{$expectedCode}] was not raised.");
        } catch (ClassifierCandidateStagingException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }
    }

    private function assertNegativePersistenceAssertions(): void
    {
        $this->assertSame(0, StatisticalClassifierVersion::query()->count());
        $this->assertSame(0, DB::table('statistical_classifier_nodes')->count());
        $this->assertSame(0, DB::table('statistical_classifier_active_versions')->count());
    }
}
