<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ClassifierParserIdentity;
use App\Domain\PriceIndices\Application\Data\ClassifierValidationSummary;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierNode;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Application\Services\ClassifierCandidatePersistenceObserver;
use App\Domain\PriceIndices\Application\Services\FindEquivalentClassifierVersion;
use App\Domain\PriceIndices\Application\Services\ParseOkpd2ClassifierArtifact;
use App\Domain\PriceIndices\Application\Services\PersistTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Application\Services\ValidateClassifierCandidateSnapshot;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSourceTrustTier;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ClassifierCandidatePersistenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ready_candidate_is_reverified_reparsed_revalidated_and_persisted_as_one_snapshot(): void
    {
        $descriptor = $this->smallDescriptor();
        $snapshot = $this->smallSnapshot($descriptor);
        [$classifier, $source, $import] = $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);
        $validator = Mockery::mock(ValidateClassifierCandidateSnapshot::class)->makePartial();
        $validator->shouldReceive('validate')->once()->passthru();
        $this->app->instance(ValidateClassifierCandidateSnapshot::class, $validator);

        $result = app(PersistTrustedClassifierCandidate::class)->persist(
            $descriptor->candidateKey,
            CarbonImmutable::parse('2026-08-25'),
        );

        $version = StatisticalClassifierVersion::query()->sole();
        $nodes = DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', $version->id)
            ->get()
            ->keyBy('code');

        $this->assertFalse($result->reused);
        $this->assertSame($classifier->public_id, $result->classifierPublicId);
        $this->assertSame($source->public_id, $result->sourcePublicId);
        $this->assertSame($import->public_id, $result->importPublicId);
        $this->assertSame($version->public_id, $result->versionPublicId);
        $this->assertSame(ClassifierVersionStatus::Ready, $version->status);
        $this->assertSame(count($snapshot->nodes), $version->node_count);
        $this->assertCount(count($snapshot->nodes), $nodes);
        $this->assertSame('Canonical category', $nodes->get($descriptor->controlNodeCode)->name);
        $this->assertSame('canonical category', $nodes->get($descriptor->controlNodeCode)->normalized_name);
        $this->assertSame('category', $nodes->get($descriptor->controlNodeCode)->semantic_level);
        $this->assertSame('Official note', $nodes->get($descriptor->controlNodeCode)->notes_text);
        $this->assertSame('10.01.10', $this->parentCode($nodes->get('10.01.10.100')->parent_node_id));
        $this->assertSame('10.01.10', $this->parentCode($nodes->get('10.01.10.111')->parent_node_id));
        $this->assertFalse($nodes->has('10.01.10.110'));
        $this->assertSame(range(1, count($snapshot->nodes)), $nodes->sortBy('source_order')->pluck('source_order')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(ClassifierImportStatus::Ready, $import->fresh()->status);
        $this->assertSame(0, DB::table('statistical_classifier_active_versions')->count());
        $this->assertSame(0, DB::table('statistical_classifier_items')->count());

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($source->storage_path, $encoded);
        $this->assertStringNotContainsString('classifier_id', $encoded);
        $this->assertStringNotContainsString('source_file_id', $encoded);
    }

    public function test_explicit_as_of_date_creates_a_scheduled_version_without_activation(): void
    {
        $descriptor = $this->smallDescriptor();
        $snapshot = $this->smallSnapshot($descriptor);
        $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);

        $result = app(PersistTrustedClassifierCandidate::class)->persist(
            $descriptor->candidateKey,
            CarbonImmutable::parse('2026-07-05'),
        );

        $this->assertSame('scheduled', $result->status);
        $this->assertSame(ClassifierVersionStatus::Scheduled, StatisticalClassifierVersion::query()->sole()->status);
        $this->assertSame(0, DB::table('statistical_classifier_active_versions')->count());
    }

    public function test_second_persistence_reuses_the_version_without_reverify_or_reparse(): void
    {
        $descriptor = $this->smallDescriptor();
        $snapshot = $this->smallSnapshot($descriptor);
        $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);
        $service = app(PersistTrustedClassifierCandidate::class);

        $first = $service->persist($descriptor->candidateKey, CarbonImmutable::parse('2026-08-25'));
        $second = $service->persist($descriptor->candidateKey, CarbonImmutable::parse('2026-08-25'));

        $this->assertFalse($first->reused);
        $this->assertTrue($second->reused);
        $this->assertSame($first->versionPublicId, $second->versionPublicId);
        $this->assertSame(1, StatisticalClassifierVersion::query()->count());
        $this->assertSame(count($snapshot->nodes), DB::table('statistical_classifier_nodes')->count());
        $this->assertSame(0.0, $second->parseElapsedMilliseconds);
    }

    public function test_transaction_preflight_returns_an_equivalent_winner_created_after_outer_preflight(): void
    {
        $descriptor = $this->smallDescriptor();
        $snapshot = $this->smallSnapshot($descriptor);
        $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);
        $service = app(PersistTrustedClassifierCandidate::class);
        $winnerResult = $service->persist(
            $descriptor->candidateKey,
            CarbonImmutable::parse('2026-08-25'),
        );
        $winner = StatisticalClassifierVersion::query()->sole();

        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);
        $versions = Mockery::mock(FindEquivalentClassifierVersion::class);
        $versions->shouldReceive('find')->twice()->andReturn(null, $winner);
        $this->app->instance(FindEquivalentClassifierVersion::class, $versions);

        $raceResult = app(PersistTrustedClassifierCandidate::class)->persist(
            $descriptor->candidateKey,
            CarbonImmutable::parse('2026-08-25'),
        );

        $this->assertTrue($raceResult->reused);
        $this->assertSame($winnerResult->versionPublicId, $raceResult->versionPublicId);
        $this->assertSame(1, StatisticalClassifierVersion::query()->count());
        $this->assertSame(count($snapshot->nodes), DB::table('statistical_classifier_nodes')->count());
    }

    public function test_missing_equivalent_ready_import_fails_without_automatically_staging(): void
    {
        $descriptor = $this->smallDescriptor();
        $this->bindDescriptor($descriptor);
        $classifier = StatisticalClassifier::factory()->create(['code' => $descriptor->classifierCode]);
        StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create([
                'sha256' => $descriptor->sourceSha256,
                'trust_tier' => ClassifierSourceTrustTier::OfficialAuthoritative,
            ]);

        $this->assertPersistenceError($descriptor, 'candidate_import_not_ready');
        $this->assertSame(0, StatisticalClassifierImport::query()->count());
        $this->assertNoPersistenceRows();
    }

    public function test_corrupted_artifact_blocks_persistence_and_preserves_ready_import(): void
    {
        $descriptor = $this->smallDescriptor();
        [, , $import] = $this->arrangeReadyCandidate($descriptor);
        $storage = Mockery::mock(ClassifierArtifactStorage::class);
        $storage->shouldReceive('verify')->once()->andThrow(new ClassifierAcquisitionException(
            'storage_integrity_failure',
            'private path and SQL must not escape',
        ));
        $this->app->instance(ClassifierArtifactStorage::class, $storage);
        $parser = Mockery::mock(ParseOkpd2ClassifierArtifact::class);
        $parser->shouldNotReceive('identity');
        $parser->shouldNotReceive('parse');
        $this->app->instance(ParseOkpd2ClassifierArtifact::class, $parser);

        $this->assertPersistenceError($descriptor, 'source_artifact_integrity_failure');
        $this->assertSame(ClassifierImportStatus::Ready, $import->fresh()->status);
        $this->assertNoPersistenceRows();
    }

    public function test_exact_revalidation_mismatch_creates_no_version_or_nodes(): void
    {
        $descriptor = $this->smallDescriptor();
        $valid = $this->smallSnapshot($descriptor);
        $invalid = new ParsedClassifierSnapshot(
            parserCode: $valid->parserCode,
            parserVersion: $valid->parserVersion,
            sectionsCount: $valid->sectionsCount,
            digitalNodesCount: $valid->digitalNodesCount,
            totalNodesCount: $valid->totalNodesCount,
            nodes: $valid->nodes,
            warnings: $valid->warnings,
            validationSummary: new ClassifierValidationSummary(
                fatalErrors: [],
                warnings: [],
                metrics: [
                    'notes_count' => 0,
                    'level_counts' => $descriptor->expectedLevelCounts,
                ],
            ),
        );
        [, , $import] = $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $invalid);

        $this->assertPersistenceError($descriptor, 'candidate_notes_count_mismatch');
        $this->assertNoPersistenceRows();
        $this->assertSame(ClassifierImportStatus::Ready, $import->fresh()->status);
    }

    #[DataProvider('conflictingVersionProvider')]
    public function test_same_version_label_with_conflicting_provenance_is_never_overwritten(string $variant): void
    {
        $descriptor = $this->smallDescriptor();
        [$classifier, $source] = $this->arrangeReadyCandidate($descriptor);
        $conflictingSource = $source;
        $attributes = [
            'attempt' => 2,
            'status' => ClassifierImportStatus::Ready,
            'parser_code' => $descriptor->parserCode,
            'parser_version' => (string) $descriptor->parserVersion,
            'validation_summary_json' => $this->readySummary($descriptor),
        ];

        if ($variant === 'source') {
            $conflictingSource = StatisticalClassifierSourceFile::factory()
                ->for($classifier, 'classifier')
                ->create(['sha256' => hash('sha256', 'conflicting-source')]);
            $attributes['attempt'] = 1;
        } elseif ($variant === 'parser') {
            $attributes['parser_version'] = '999';
        } elseif ($variant === 'fingerprint') {
            $attributes['validation_summary_json']['candidate_fingerprint'] = hash('sha256', 'historical');
        }

        $conflictingImport = StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($conflictingSource, 'sourceFile')
            ->create($attributes);
        StatisticalClassifierVersion::factory()
            ->for($classifier, 'classifier')
            ->for($conflictingImport, 'classifierImport')
            ->create([
                'version_label' => $descriptor->versionLabel,
                'effective_from' => $descriptor->effectiveFrom,
            ]);

        $this->assertPersistenceError($descriptor, 'candidate_version_conflict');
        $this->assertSame(1, StatisticalClassifierVersion::query()->count());
        $this->assertSame(0, DB::table('statistical_classifier_nodes')->count());
    }

    /** @return array<string, array{string}> */
    public static function conflictingVersionProvider(): array
    {
        return [
            'different source' => ['source'],
            'different parser' => ['parser'],
            'different fingerprint' => ['fingerprint'],
            'different equivalent import' => ['import_mismatch'],
        ];
    }

    #[DataProvider('failurePointProvider')]
    public function test_every_injected_transaction_failure_rolls_back_version_and_all_nodes(string $failurePoint): void
    {
        $descriptor = $this->syntheticDescriptor(1_001);
        $snapshot = $this->syntheticSnapshot($descriptor, 1_001);
        [, , $import] = $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);
        $observer = new class($failurePoint) extends ClassifierCandidatePersistenceObserver
        {
            public function __construct(private readonly string $failurePoint) {}

            public function reached(string $point, int $processedNodes = 0): void
            {
                if ($point === $this->failurePoint) {
                    throw new RuntimeException("Injected persistence failure at {$point}: {$processedNodes}");
                }
            }
        };
        $this->app->instance(ClassifierCandidatePersistenceObserver::class, $observer);

        try {
            app(PersistTrustedClassifierCandidate::class)->persist(
                $descriptor->candidateKey,
                CarbonImmutable::parse('2026-08-25'),
            );
            $this->fail("Failure point [{$failurePoint}] was not reached.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($failurePoint, $exception->getMessage());
        }

        $this->assertNoPersistenceRows();
        $this->assertSame(ClassifierImportStatus::Ready, $import->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function failurePointProvider(): array
    {
        return [
            'after version create' => [ClassifierCandidatePersistenceObserver::AFTER_VERSION_CREATE],
            'after partial insert batches' => [ClassifierCandidatePersistenceObserver::AFTER_NODE_INSERT_BATCH],
            'after all node inserts' => [ClassifierCandidatePersistenceObserver::AFTER_NODE_INSERTS],
            'during parent updates' => [ClassifierCandidatePersistenceObserver::AFTER_PARENT_UPDATE_BATCH],
            'after parent updates' => [ClassifierCandidatePersistenceObserver::AFTER_PARENT_UPDATES],
            'before final integrity success' => [ClassifierCandidatePersistenceObserver::BEFORE_INTEGRITY_SUCCESS],
        ];
    }

    public function test_large_synthetic_snapshot_uses_bounded_bulk_queries_and_reports_benchmark(): void
    {
        $digitalNodes = 20_050;
        $descriptor = $this->syntheticDescriptor($digitalNodes);
        $snapshot = $this->syntheticSnapshot($descriptor, $digitalNodes);
        $this->arrangeReadyCandidate($descriptor);
        $this->mockIntegrity();
        $this->mockParser($descriptor, $snapshot);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = app(PersistTrustedClassifierCandidate::class)->persist(
                $descriptor->candidateKey,
                CarbonImmutable::parse('2026-08-25'),
            );
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        fwrite(STDERR, "\nOKPD2 2D-B synthetic persistence benchmark: ".json_encode([
            'nodes' => $result->nodeCount,
            'query_count' => $queryCount,
            'insert_batch_size' => 500,
            'parent_update_batch_size' => 500,
            'insert_ms' => $result->performance['insert_ms'],
            'map_ms' => $result->performance['map_ms'],
            'parent_update_ms' => $result->performance['parent_update_ms'],
            'integrity_ms' => $result->performance['integrity_ms'],
            'transaction_ms' => $result->persistenceElapsedMilliseconds,
            'peak_memory_bytes' => $result->performance['peak_memory_bytes'],
            'peak_memory_delta_bytes' => $result->performance['peak_memory_delta_bytes'],
        ], JSON_THROW_ON_ERROR)."\n");

        $this->assertSame(20_051, $result->nodeCount);
        $this->assertSame(20_051, DB::table('statistical_classifier_nodes')->count());
        $this->assertLessThan(200, $queryCount);
        $this->assertGreaterThan(0, $result->performance['insert_ms']);
        $this->assertGreaterThan(0, $result->performance['parent_update_ms']);
        $this->assertGreaterThan(0, $result->performance['peak_memory_bytes']);
    }

    private function smallDescriptor(): TrustedClassifierCandidateDescriptor
    {
        return new TrustedClassifierCandidateDescriptor(
            candidateKey: 'test_okpd2_small',
            classifierCode: 'okpd2',
            versionLabel: 'test-145/2026-small',
            effectiveFrom: '2026-07-06',
            sourceSha256: hash('sha256', 'small-official-artifact'),
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

    private function smallSnapshot(TrustedClassifierCandidateDescriptor $descriptor): ParsedClassifierSnapshot
    {
        $nodes = [
            $this->node('A', 'Section A', ClassifierSemanticLevel::Section, 0, 1, null),
            $this->node('10', 'Class 10', ClassifierSemanticLevel::ClassLevel, 1, 2, 'A'),
            $this->node('10.0', 'Subclass 10.0', ClassifierSemanticLevel::Subclass, 2, 3, '10'),
            $this->node('10.01', 'Group 10.01', ClassifierSemanticLevel::Group, 3, 4, '10.0'),
            $this->node('10.01.1', 'Subgroup 10.01.1', ClassifierSemanticLevel::Subgroup, 4, 5, '10.01'),
            $this->node('10.01.10', 'Type 10.01.10', ClassifierSemanticLevel::Type, 5, 6, '10.01.1'),
            $this->node(
                '10.01.10.100',
                $descriptor->controlNodeName,
                ClassifierSemanticLevel::Category,
                6,
                7,
                '10.01.10',
                'Official note',
                ['source_part' => 'part-1.docx', 'source_row' => 10],
            ),
            $this->node('10.01.10.101', 'Direct subcategory', ClassifierSemanticLevel::Subcategory, 7, 8, '10.01.10.100'),
            $this->node('10.01.10.111', 'Fallback subcategory', ClassifierSemanticLevel::Subcategory, 7, 9, '10.01.10'),
        ];

        return $this->snapshot($descriptor, $nodes);
    }

    private function syntheticDescriptor(int $digitalNodes): TrustedClassifierCandidateDescriptor
    {
        return new TrustedClassifierCandidateDescriptor(
            candidateKey: "test_okpd2_synthetic_{$digitalNodes}",
            classifierCode: 'okpd2',
            versionLabel: "synthetic-{$digitalNodes}/2026",
            effectiveFrom: '2026-07-06',
            sourceSha256: hash('sha256', "synthetic-artifact-{$digitalNodes}"),
            parserCode: 'okpd2_rosstat_docx',
            parserVersion: 1,
            expectedSectionsCount: 1,
            expectedDigitalNodesCount: $digitalNodes,
            expectedTotalNodesCount: $digitalNodes + 1,
            expectedNotesCount: 0,
            expectedWarningsCount: 0,
            expectedLevelCounts: ['category' => $digitalNodes],
            controlNodeCode: 'N00001',
            controlNodeName: 'Synthetic node 1',
            controlNodeLevel: ClassifierSemanticLevel::Category,
            controlNodeParentCode: 'A',
            controlAncestorParents: ['A' => null],
        );
    }

    private function syntheticSnapshot(
        TrustedClassifierCandidateDescriptor $descriptor,
        int $digitalNodes,
    ): ParsedClassifierSnapshot {
        $nodes = [$this->node('A', 'Section A', ClassifierSemanticLevel::Section, 0, 1, null)];

        for ($index = 1; $index <= $digitalNodes; $index++) {
            $code = 'N'.str_pad((string) $index, 5, '0', STR_PAD_LEFT);
            $nodes[] = $this->node(
                $code,
                "Synthetic node {$index}",
                ClassifierSemanticLevel::Category,
                1,
                $index + 1,
                'A',
            );
        }

        return $this->snapshot($descriptor, $nodes);
    }

    /** @param list<ParsedClassifierNode> $nodes */
    private function snapshot(
        TrustedClassifierCandidateDescriptor $descriptor,
        array $nodes,
    ): ParsedClassifierSnapshot {
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

    /** @param array<string, int|string|bool|null>|null $metadata */
    private function node(
        string $code,
        string $name,
        ClassifierSemanticLevel $level,
        int $formalDepth,
        int $sourceOrder,
        ?string $parentCode,
        ?string $notes = null,
        ?array $metadata = null,
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
            metadata: $metadata,
        );
    }

    /** @return array{StatisticalClassifier, StatisticalClassifierSourceFile, StatisticalClassifierImport} */
    private function arrangeReadyCandidate(TrustedClassifierCandidateDescriptor $descriptor): array
    {
        $this->bindDescriptor($descriptor);
        $classifier = StatisticalClassifier::factory()->create(['code' => $descriptor->classifierCode]);
        $source = StatisticalClassifierSourceFile::factory()
            ->for($classifier, 'classifier')
            ->create([
                'sha256' => $descriptor->sourceSha256,
                'trust_tier' => ClassifierSourceTrustTier::OfficialAuthoritative,
                'storage_disk' => 'price_indices_classifier_artifacts',
                'storage_path' => "classifiers/okpd2/artifacts/{$descriptor->sourceSha256}.zip",
                'size_bytes' => 123_456,
            ]);
        $import = StatisticalClassifierImport::factory()
            ->for($classifier, 'classifier')
            ->for($source, 'sourceFile')
            ->create([
                'attempt' => 1,
                'status' => ClassifierImportStatus::Ready,
                'parser_code' => $descriptor->parserCode,
                'parser_version' => (string) $descriptor->parserVersion,
                'started_at' => now(),
                'finished_at' => now(),
                'nodes_parsed' => $descriptor->expectedTotalNodesCount,
                'sections_count' => $descriptor->expectedSectionsCount,
                'validation_summary_json' => $this->readySummary($descriptor),
            ]);

        return [$classifier, $source, $import];
    }

    /** @return array<string, mixed> */
    private function readySummary(TrustedClassifierCandidateDescriptor $descriptor): array
    {
        return [
            'candidate_key' => $descriptor->candidateKey,
            'candidate_fingerprint' => $descriptor->fingerprint(),
            'version_label' => $descriptor->versionLabel,
            'effective_from' => $descriptor->effectiveFrom,
            'source' => ['sha256' => $descriptor->sourceSha256],
            'parser' => ['code' => $descriptor->parserCode, 'version' => $descriptor->parserVersion],
            'metrics' => [
                'sections_count' => $descriptor->expectedSectionsCount,
                'digital_nodes_count' => $descriptor->expectedDigitalNodesCount,
                'total_nodes_count' => $descriptor->expectedTotalNodesCount,
                'warnings_count' => $descriptor->expectedWarningsCount,
            ],
            'level_counts' => $descriptor->expectedLevelCounts,
            'notes_count' => $descriptor->expectedNotesCount,
        ];
    }

    private function bindDescriptor(TrustedClassifierCandidateDescriptor $descriptor): void
    {
        $registry = Mockery::mock(TrustedClassifierCandidateRegistry::class);
        $registry->shouldReceive('get')->with($descriptor->candidateKey)->andReturn($descriptor);
        $this->app->instance(TrustedClassifierCandidateRegistry::class, $registry);
    }

    private function mockIntegrity(): void
    {
        $storage = Mockery::mock(ClassifierArtifactStorage::class);
        $storage->shouldReceive('verify')->once()->andReturnNull();
        $this->app->instance(ClassifierArtifactStorage::class, $storage);
    }

    private function mockParser(
        TrustedClassifierCandidateDescriptor $descriptor,
        ParsedClassifierSnapshot $snapshot,
    ): void {
        $parser = Mockery::mock(ParseOkpd2ClassifierArtifact::class);
        $parser->shouldReceive('identity')->once()->andReturn(new ClassifierParserIdentity(
            $descriptor->parserCode,
            $descriptor->parserVersion,
        ));
        $parser->shouldReceive('parse')->once()->andReturn($snapshot);
        $this->app->instance(ParseOkpd2ClassifierArtifact::class, $parser);
    }

    private function parentCode(int $parentId): string
    {
        return (string) DB::table('statistical_classifier_nodes')->where('id', $parentId)->value('code');
    }

    private function assertPersistenceError(
        TrustedClassifierCandidateDescriptor $descriptor,
        string $expectedCode,
    ): void {
        try {
            app(PersistTrustedClassifierCandidate::class)->persist(
                $descriptor->candidateKey,
                CarbonImmutable::parse('2026-08-25'),
            );
            $this->fail("Persistence error [{$expectedCode}] was not raised.");
        } catch (ClassifierCandidateStagingException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }
    }

    private function assertNoPersistenceRows(): void
    {
        $this->assertSame(0, StatisticalClassifierVersion::query()->count());
        $this->assertSame(0, DB::table('statistical_classifier_nodes')->count());
        $this->assertSame(0, DB::table('statistical_classifier_active_versions')->count());
    }
}
