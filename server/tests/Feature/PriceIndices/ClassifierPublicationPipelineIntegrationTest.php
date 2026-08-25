<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ClassifierParserIdentity;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationSummary;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Application\Services\ActivateStatisticalClassifierVersion;
use App\Domain\PriceIndices\Application\Services\ParseOkpd2ClassifierArtifact;
use App\Domain\PriceIndices\Application\Services\PersistTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\StageTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ClassifierPublicationPipelineIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_trusted_offline_candidate_is_staged_persisted_and_only_explicitly_activated(): void
    {
        $descriptor = $this->descriptor();
        $snapshot = $this->snapshot($descriptor);
        $classifier = StatisticalClassifier::factory()->create(['code' => $descriptor->classifierCode]);
        StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create([
                'sha256' => $descriptor->sourceSha256,
                'trust_tier' => ClassifierSourceTrustTier::OfficialAuthoritative,
                'storage_disk' => 'price_indices_classifier_artifacts',
                'storage_path' => "classifiers/okpd2/artifacts/{$descriptor->sourceSha256}.zip",
                'size_bytes' => 123_456,
            ]);
        $registry = Mockery::mock(TrustedClassifierCandidateRegistry::class);
        $registry->shouldReceive('get')->with($descriptor->candidateKey)->andReturn($descriptor);
        $this->app->instance(TrustedClassifierCandidateRegistry::class, $registry);
        $storage = Mockery::mock(ClassifierArtifactStorage::class);
        $storage->shouldReceive('verify')->times(3)->andReturnNull();
        $this->app->instance(ClassifierArtifactStorage::class, $storage);
        $parser = Mockery::mock(ParseOkpd2ClassifierArtifact::class);
        $parser->shouldReceive('identity')->twice()->andReturn(new ClassifierParserIdentity(
            $descriptor->parserCode,
            $descriptor->parserVersion,
        ));
        $parser->shouldReceive('parse')->twice()->andReturn($snapshot);
        $this->app->instance(ParseOkpd2ClassifierArtifact::class, $parser);
        $asOfDate = CarbonImmutable::parse('2026-08-25');

        $staged = app(StageTrustedClassifierCandidate::class)->stage($descriptor->candidateKey);
        $this->assertFalse($staged->reused);
        $this->assertDatabaseCount('statistical_classifier_imports', 1);
        $this->assertDatabaseCount('statistical_classifier_versions', 0);
        $this->assertDatabaseCount('statistical_classifier_active_versions', 0);

        $persisted = app(PersistTrustedClassifierCandidate::class)->persist(
            $descriptor->candidateKey,
            $asOfDate,
        );
        $this->assertFalse($persisted->reused);
        $this->assertSame('ready', $persisted->status);
        $this->assertDatabaseCount('statistical_classifier_versions', 1);
        $this->assertDatabaseCount('statistical_classifier_nodes', 9);
        $this->assertDatabaseCount('statistical_classifier_active_versions', 0);

        $activated = app(ActivateStatisticalClassifierVersion::class)->activate(
            $descriptor->candidateKey,
            $asOfDate,
            CarbonImmutable::parse('2026-08-25 12:00:00'),
            reason: 'test:pipeline',
        );
        $version = StatisticalClassifierVersion::query()->sole();
        $this->assertSame('activated', $activated->status);
        $this->assertSame($version->public_id, $activated->targetVersionPublicId);
        $this->assertSame($version->id, DB::table('statistical_classifier_active_versions')->sole()->classifier_version_id);

        $reusedStage = app(StageTrustedClassifierCandidate::class)->stage($descriptor->candidateKey);
        $reusedPersistence = app(PersistTrustedClassifierCandidate::class)->persist(
            $descriptor->candidateKey,
            $asOfDate,
        );
        $repeatedActivation = app(ActivateStatisticalClassifierVersion::class)->activate(
            $descriptor->candidateKey,
            $asOfDate,
            CarbonImmutable::parse('2026-08-25 13:00:00'),
            reason: 'must-not-rewrite-audit',
        );

        $this->assertTrue($reusedStage->reused);
        $this->assertTrue($reusedPersistence->reused);
        $this->assertSame('already_active', $repeatedActivation->status);
        $this->assertDatabaseCount('statistical_classifier_source_files', 1);
        $this->assertDatabaseCount('statistical_classifier_imports', 1);
        $this->assertDatabaseCount('statistical_classifier_versions', 1);
        $this->assertDatabaseCount('statistical_classifier_nodes', 9);
        $this->assertDatabaseCount('statistical_classifier_active_versions', 1);
        $this->assertDatabaseCount('statistical_classifier_items', 0);
        $this->assertSame(9, StatisticalClassifierNode::query()->where('classifier_version_id', $version->id)->count());
        $this->assertSame(1, StatisticalClassifierImport::query()->count());
    }

    private function descriptor(): TrustedClassifierCandidateDescriptor
    {
        return new TrustedClassifierCandidateDescriptor(
            candidateKey: 'test_okpd2_pipeline',
            classifierCode: 'okpd2',
            versionLabel: 'test-145/2026-pipeline',
            effectiveFrom: '2026-07-06',
            sourceSha256: hash('sha256', 'trusted-offline-pipeline-artifact'),
            parserCode: 'okpd2_rosstat_docx',
            parserVersion: 1,
            expectedSectionsCount: 1,
            expectedDigitalNodesCount: 8,
            expectedTotalNodesCount: 9,
            expectedNotesCount: 1,
            expectedWarningsCount: 0,
            expectedLevelCounts: [
                'class' => 1,
                'subclass' => 1,
                'group' => 1,
                'subgroup' => 1,
                'type' => 1,
                'category' => 1,
                'subcategory' => 2,
            ],
            controlNodeCode: '10.01.10.100',
            controlNodeName: 'Canonical category',
            controlNodeLevel: ClassifierSemanticLevel::Category,
            controlNodeParentCode: '10.01.10',
            controlAncestorParents: [
                'A' => null,
                '10' => 'A',
                '10.0' => '10',
                '10.01' => '10.0',
                '10.01.1' => '10.01',
                '10.01.10' => '10.01.1',
            ],
        );
    }

    private function snapshot(TrustedClassifierCandidateDescriptor $descriptor): ParsedClassifierSnapshot
    {
        $nodes = [
            $this->node('A', 'Section A', ClassifierSemanticLevel::Section, 0, 1, null),
            $this->node('10', 'Class 10', ClassifierSemanticLevel::ClassLevel, 1, 2, 'A'),
            $this->node('10.0', 'Subclass 10.0', ClassifierSemanticLevel::Subclass, 2, 3, '10'),
            $this->node('10.01', 'Group 10.01', ClassifierSemanticLevel::Group, 3, 4, '10.0'),
            $this->node('10.01.1', 'Subgroup 10.01.1', ClassifierSemanticLevel::Subgroup, 4, 5, '10.01'),
            $this->node('10.01.10', 'Type 10.01.10', ClassifierSemanticLevel::Type, 5, 6, '10.01.1'),
            $this->node('10.01.10.100', $descriptor->controlNodeName, ClassifierSemanticLevel::Category, 6, 7, '10.01.10', 'Official note'),
            $this->node('10.01.10.101', 'Direct subcategory', ClassifierSemanticLevel::Subcategory, 7, 8, '10.01.10.100'),
            $this->node('10.01.10.111', 'Fallback subcategory', ClassifierSemanticLevel::Subcategory, 7, 9, '10.01.10'),
        ];

        return new ParsedClassifierSnapshot(
            parserCode: $descriptor->parserCode,
            parserVersion: $descriptor->parserVersion,
            sectionsCount: $descriptor->expectedSectionsCount,
            digitalNodesCount: $descriptor->expectedDigitalNodesCount,
            totalNodesCount: $descriptor->expectedTotalNodesCount,
            nodes: $nodes,
            warnings: [],
            validationSummary: new ClassifierValidationSummary(
                fatalErrors: [],
                warnings: [],
                metrics: [
                    'notes_count' => $descriptor->expectedNotesCount,
                    'level_counts' => $descriptor->expectedLevelCounts,
                ],
            ),
        );
    }

    private function node(
        string $code,
        string $name,
        ClassifierSemanticLevel $level,
        int $formalDepth,
        int $sourceOrder,
        ?string $parentCode,
        ?string $notes = null,
    ): ParsedClassifierNode {
        return new ParsedClassifierNode(
            code: $code,
            name: $name,
            normalizedName: mb_strtolower($name, 'UTF-8'),
            semanticLevel: $level,
            formalDepth: $formalDepth,
            sourceOrder: $sourceOrder,
            parentCode: $parentCode,
            notes: $notes,
            metadata: null,
        );
    }
}
