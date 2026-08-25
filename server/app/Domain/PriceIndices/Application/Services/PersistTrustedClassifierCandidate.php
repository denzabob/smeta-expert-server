<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierCandidatePersistenceResult;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use App\Domain\PriceIndices\Domain\Enums\ClassifierVersionStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PersistTrustedClassifierCandidate
{
    private const INSERT_BATCH_SIZE = 500;

    private const PARENT_UPDATE_BATCH_SIZE = 500;

    public function __construct(
        private readonly TrustedClassifierCandidateRegistry $candidates,
        private readonly ResolveTrustedClassifierCandidateSource $sources,
        private readonly FindEquivalentReadyClassifierImport $readyImports,
        private readonly FindEquivalentClassifierVersion $versions,
        private readonly ClassifierArtifactStorage $storage,
        private readonly ParseOkpd2ClassifierArtifact $parser,
        private readonly ValidateClassifierCandidateSnapshot $validator,
        private readonly ClassifierCandidatePersistenceObserver $observer,
    ) {}

    public function persist(
        string $candidateKey,
        CarbonInterface $asOfDate,
    ): ClassifierCandidatePersistenceResult {
        $startedAt = hrtime(true);
        $descriptor = $this->candidates->get($candidateKey);
        [$classifier, $source] = $this->sources->resolve($descriptor);
        $readyImport = $this->readyImports->find($descriptor, $source);

        if ($readyImport === null) {
            throw new ClassifierCandidateStagingException(
                'candidate_import_not_ready',
                'An equivalent READY classifier import is required before persistence.',
                'ready_import_preflight',
                ['candidate_key' => $descriptor->candidateKey],
            );
        }

        $existing = $this->versions->find($descriptor, $classifier, $source, $readyImport);

        if ($existing !== null) {
            $persistenceStartedAt = hrtime(true);
            $this->assertPersistedIntegrity($existing, $descriptor);

            return $this->result(
                $descriptor,
                $classifier,
                $source,
                $readyImport,
                $existing,
                true,
                0.0,
                $this->elapsedMilliseconds($persistenceStartedAt),
                $startedAt,
                $this->emptyPerformance(),
            );
        }

        $this->verifyArtifact($descriptor, $source);
        $identity = $this->parser->identity();

        if ($identity->code !== $descriptor->parserCode || $identity->version !== $descriptor->parserVersion) {
            throw new ClassifierCandidateStagingException(
                'candidate_parser_identity_mismatch',
                'The registered parser implementation does not match the trusted candidate descriptor.',
                'parsing',
            );
        }

        $parseStartedAt = hrtime(true);

        try {
            $snapshot = $this->parser->parse($source);
        } catch (ClassifierParserException $exception) {
            throw new ClassifierCandidateStagingException(
                $exception->errorCode,
                'The classifier artifact could not be parsed safely.',
                'parsing',
                [
                    'parser_code' => $descriptor->parserCode,
                    'parser_version' => $descriptor->parserVersion,
                ],
                $exception,
            );
        } catch (Throwable $exception) {
            throw new ClassifierCandidateStagingException(
                'classifier_candidate_parsing_failed',
                'The classifier artifact could not be parsed safely.',
                'parsing',
                [
                    'parser_code' => $descriptor->parserCode,
                    'parser_version' => $descriptor->parserVersion,
                ],
                $exception,
            );
        }

        $parseElapsed = $this->elapsedMilliseconds($parseStartedAt);
        $this->validator->validate($descriptor, $source, $snapshot);
        $this->assertSnapshotPersistenceShape($descriptor, $snapshot);
        $persistenceStartedAt = hrtime(true);

        try {
            $outcome = DB::transaction(function () use (
                $asOfDate,
                $classifier,
                $descriptor,
                $readyImport,
                $snapshot,
                $source,
            ): array {
                $lockedImport = StatisticalClassifierImport::query()
                    ->whereKey($readyImport->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedImport instanceof StatisticalClassifierImport
                    || $lockedImport->status !== ClassifierImportStatus::Ready
                    || $lockedImport->classifier_id !== $classifier->id
                    || $lockedImport->source_file_id !== $source->id
                    || $lockedImport->parser_code !== $descriptor->parserCode
                    || $lockedImport->parser_version !== (string) $descriptor->parserVersion
                    || ! $this->readyImports->hasCandidateProvenance($lockedImport, $descriptor)
                ) {
                    throw new ClassifierCandidateStagingException(
                        'candidate_import_not_ready',
                        'The equivalent READY classifier import changed before persistence.',
                        'ready_import_preflight',
                        ['candidate_key' => $descriptor->candidateKey],
                    );
                }

                $winner = $this->versions->find(
                    $descriptor,
                    $classifier,
                    $source,
                    $lockedImport,
                    lockForUpdate: true,
                );

                if ($winner !== null) {
                    $this->assertPersistedIntegrity($winner, $descriptor);

                    return [
                        'version' => $winner,
                        'reused' => true,
                        'performance' => $this->emptyPerformance(),
                    ];
                }

                $peakMemoryBefore = memory_get_peak_usage(true);
                $version = StatisticalClassifierVersion::query()->create([
                    'classifier_id' => $classifier->id,
                    'classifier_import_id' => $lockedImport->id,
                    'version_label' => $descriptor->versionLabel,
                    'effective_from' => $descriptor->effectiveFrom,
                    'effective_to' => null,
                    'approved_at' => null,
                    'source_published_at' => null,
                    'status' => $this->versionStatus($descriptor, $asOfDate),
                    'node_count' => $snapshot->totalNodesCount,
                    'metadata' => null,
                ]);
                $this->observer->reached(ClassifierCandidatePersistenceObserver::AFTER_VERSION_CREATE);

                $insertStartedAt = hrtime(true);
                $this->insertNodes($version, $snapshot);
                $insertElapsed = $this->elapsedMilliseconds($insertStartedAt);

                $mapStartedAt = hrtime(true);
                $nodeIds = DB::table('statistical_classifier_nodes')
                    ->where('classifier_version_id', $version->id)
                    ->pluck('id', 'code')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if (count($nodeIds) !== $snapshot->totalNodesCount) {
                    $this->integrityFailure('candidate_persisted_code_map_mismatch');
                }

                $mapElapsed = $this->elapsedMilliseconds($mapStartedAt);
                $parentStartedAt = hrtime(true);
                $this->updateParents($version, $snapshot, $nodeIds);
                $parentElapsed = $this->elapsedMilliseconds($parentStartedAt);

                $integrityStartedAt = hrtime(true);
                $this->assertPersistedIntegrity($version, $descriptor, $snapshot);
                $this->observer->reached(ClassifierCandidatePersistenceObserver::BEFORE_INTEGRITY_SUCCESS);
                $integrityElapsed = $this->elapsedMilliseconds($integrityStartedAt);

                return [
                    'version' => $version,
                    'reused' => false,
                    'performance' => [
                        'insert_ms' => $insertElapsed,
                        'map_ms' => $mapElapsed,
                        'parent_update_ms' => $parentElapsed,
                        'integrity_ms' => $integrityElapsed,
                        'peak_memory_bytes' => memory_get_peak_usage(true),
                        'peak_memory_delta_bytes' => max(0, memory_get_peak_usage(true) - $peakMemoryBefore),
                    ],
                ];
            }, 3);
        } catch (QueryException $exception) {
            if (! $this->isExpectedVersionRace($exception)) {
                throw $exception;
            }

            $winner = $this->versions->find($descriptor, $classifier, $source, $readyImport);

            if ($winner === null) {
                throw $exception;
            }

            $this->assertPersistedIntegrity($winner, $descriptor);
            $outcome = [
                'version' => $winner,
                'reused' => true,
                'performance' => $this->emptyPerformance(),
            ];
        }

        $persistenceElapsed = $this->elapsedMilliseconds($persistenceStartedAt);

        return $this->result(
            $descriptor,
            $classifier,
            $source,
            $readyImport,
            $outcome['version'],
            $outcome['reused'],
            $parseElapsed,
            $persistenceElapsed,
            $startedAt,
            $outcome['performance'],
        );
    }

    private function verifyArtifact(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifierSourceFile $source,
    ): void {
        if (! hash_equals($descriptor->sourceSha256, strtolower($source->sha256))) {
            $this->integrityFailure('source_artifact_integrity_failure', 'artifact_integrity');
        }

        try {
            $this->storage->verify(
                $source->storage_disk,
                $source->storage_path,
                $source->sha256,
                $source->size_bytes,
            );
        } catch (Throwable $exception) {
            throw new ClassifierCandidateStagingException(
                'source_artifact_integrity_failure',
                'The exact classifier source artifact failed immutable integrity verification.',
                'artifact_integrity',
                [],
                $exception,
            );
        }
    }

    private function versionStatus(
        TrustedClassifierCandidateDescriptor $descriptor,
        CarbonInterface $asOfDate,
    ): ClassifierVersionStatus {
        return CarbonImmutable::parse($descriptor->effectiveFrom)
            ->startOfDay()
            ->lte($asOfDate->toImmutable()->startOfDay())
            ? ClassifierVersionStatus::Ready
            : ClassifierVersionStatus::Scheduled;
    }

    private function insertNodes(
        StatisticalClassifierVersion $version,
        ParsedClassifierSnapshot $snapshot,
    ): void {
        $timestamp = now();
        $inserted = 0;
        $total = count($snapshot->nodes);

        for ($offset = 0; $offset < $total; $offset += self::INSERT_BATCH_SIZE) {
            $rows = [];

            foreach (array_slice($snapshot->nodes, $offset, self::INSERT_BATCH_SIZE) as $node) {
                $rows[] = [
                    'public_id' => (string) Str::uuid(),
                    'classifier_version_id' => $version->id,
                    'code' => $node->code,
                    'name' => $node->name,
                    'normalized_name' => $node->normalizedName,
                    'semantic_level' => $node->semanticLevel->value,
                    'formal_depth' => $node->formalDepth,
                    'parent_node_id' => null,
                    'source_order' => $node->sourceOrder,
                    'notes_text' => $node->notes,
                    'metadata_json' => $node->metadata === null
                        ? null
                        : json_encode($node->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            DB::table('statistical_classifier_nodes')->insert($rows);
            $inserted += count($rows);
            $this->observer->reached(
                ClassifierCandidatePersistenceObserver::AFTER_NODE_INSERT_BATCH,
                $inserted,
            );
        }

        $this->observer->reached(ClassifierCandidatePersistenceObserver::AFTER_NODE_INSERTS, $inserted);
    }

    /** @param array<string, int> $nodeIds */
    private function updateParents(
        StatisticalClassifierVersion $version,
        ParsedClassifierSnapshot $snapshot,
        array $nodeIds,
    ): void {
        $updates = [];

        foreach ($snapshot->nodes as $node) {
            if ($node->parentCode === null) {
                continue;
            }

            $nodeId = $nodeIds[$node->code] ?? null;
            $parentId = $nodeIds[$node->parentCode] ?? null;

            if (! is_int($nodeId) || ! is_int($parentId)) {
                throw new ClassifierCandidateStagingException(
                    'candidate_parent_missing',
                    'A parsed classifier parent target is missing from the persisted version.',
                    'parent_resolution',
                    ['code' => $node->code],
                );
            }

            $updates[] = ['id' => $nodeId, 'parent_id' => $parentId];
        }

        $processed = 0;

        foreach (array_chunk($updates, self::PARENT_UPDATE_BATCH_SIZE) as $chunk) {
            $cases = [];
            $bindings = [];
            $ids = [];

            foreach ($chunk as $update) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = $update['id'];
                $bindings[] = $update['parent_id'];
                $ids[] = $update['id'];
            }

            $bindings[] = $version->id;
            array_push($bindings, ...$ids);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $sql = 'UPDATE statistical_classifier_nodes '
                .'SET parent_node_id = CASE id '.implode(' ', $cases).' ELSE parent_node_id END '
                .'WHERE classifier_version_id = ? AND id IN ('.$placeholders.')';
            $affected = DB::update($sql, $bindings);

            if ($affected !== count($chunk)) {
                $this->integrityFailure('candidate_parent_update_count_mismatch');
            }

            $processed += count($chunk);
            $this->observer->reached(
                ClassifierCandidatePersistenceObserver::AFTER_PARENT_UPDATE_BATCH,
                $processed,
            );
        }

        $this->observer->reached(ClassifierCandidatePersistenceObserver::AFTER_PARENT_UPDATES, $processed);
    }

    private function assertSnapshotPersistenceShape(
        TrustedClassifierCandidateDescriptor $descriptor,
        ParsedClassifierSnapshot $snapshot,
    ): void {
        $codes = [];
        $sourceOrders = [];
        $levelCounts = [];
        $notesCount = 0;
        $rootCount = 0;

        foreach ($snapshot->nodes as $node) {
            if (isset($codes[$node->code]) || isset($sourceOrders[$node->sourceOrder])) {
                $this->integrityFailure('candidate_snapshot_persistence_shape_invalid', 'pre_persistence_validation');
            }

            $codes[$node->code] = true;
            $sourceOrders[$node->sourceOrder] = true;
            $levelCounts[$node->semanticLevel->value] = ($levelCounts[$node->semanticLevel->value] ?? 0) + 1;
            $notesCount += $node->notes === null ? 0 : 1;

            if ($node->parentCode === null) {
                $rootCount++;

                if ($node->semanticLevel !== ClassifierSemanticLevel::Section) {
                    $this->integrityFailure('candidate_snapshot_persistence_shape_invalid', 'pre_persistence_validation');
                }
            } elseif (! isset($codes[$node->parentCode])) {
                // Parent order is not semantically relevant, so defer the complete lookup until below.
            }
        }

        foreach ($snapshot->nodes as $node) {
            if ($node->parentCode !== null && ! isset($codes[$node->parentCode])) {
                $this->integrityFailure('candidate_parent_missing', 'pre_persistence_validation');
            }
        }

        $expectedLevels = $descriptor->expectedLevelCounts;
        $expectedLevels[ClassifierSemanticLevel::Section->value] = $descriptor->expectedSectionsCount;
        ksort($expectedLevels);
        ksort($levelCounts);
        $orders = array_keys($sourceOrders);
        sort($orders, SORT_NUMERIC);

        if (count($snapshot->nodes) !== $snapshot->totalNodesCount
            || count($snapshot->nodes) !== $descriptor->expectedTotalNodesCount
            || $notesCount !== $descriptor->expectedNotesCount
            || $rootCount !== $descriptor->expectedSectionsCount
            || $levelCounts !== $expectedLevels
            || $orders !== range(1, $snapshot->totalNodesCount)
        ) {
            $this->integrityFailure('candidate_snapshot_persistence_shape_invalid', 'pre_persistence_validation');
        }
    }

    private function assertPersistedIntegrity(
        StatisticalClassifierVersion $version,
        TrustedClassifierCandidateDescriptor $descriptor,
        ?ParsedClassifierSnapshot $snapshot = null,
    ): void {
        $expectedTotal = $snapshot?->totalNodesCount ?? $descriptor->expectedTotalNodesCount;
        $aggregate = DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', $version->id)
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COUNT(DISTINCT code) AS distinct_code_count')
            ->selectRaw('SUM(CASE WHEN parent_node_id IS NULL THEN 1 ELSE 0 END) AS root_count')
            ->selectRaw('SUM(CASE WHEN parent_node_id IS NOT NULL THEN 1 ELSE 0 END) AS parent_count')
            ->selectRaw('SUM(CASE WHEN notes_text IS NOT NULL THEN 1 ELSE 0 END) AS notes_count')
            ->selectRaw('SUM(CASE WHEN source_order IS NULL THEN 1 ELSE 0 END) AS null_order_count')
            ->selectRaw('COUNT(DISTINCT source_order) AS distinct_order_count')
            ->selectRaw('MIN(source_order) AS min_order')
            ->selectRaw('MAX(source_order) AS max_order')
            ->first();

        if ($aggregate === null
            || (int) $aggregate->total_count !== $expectedTotal
            || (int) $aggregate->distinct_code_count !== $expectedTotal
            || (int) $aggregate->root_count !== $descriptor->expectedSectionsCount
            || (int) $aggregate->parent_count !== $descriptor->expectedDigitalNodesCount
            || (int) $aggregate->notes_count !== $descriptor->expectedNotesCount
            || (int) $aggregate->null_order_count !== 0
            || (int) $aggregate->distinct_order_count !== $expectedTotal
            || (int) $aggregate->min_order !== 1
            || (int) $aggregate->max_order !== $expectedTotal
            || $version->node_count !== $expectedTotal
        ) {
            $this->integrityFailure('candidate_persisted_integrity_mismatch');
        }

        $actualLevels = DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', $version->id)
            ->selectRaw('semantic_level, COUNT(*) AS aggregate')
            ->groupBy('semantic_level')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->semantic_level => (int) $row->aggregate])
            ->all();
        $expectedLevels = $descriptor->expectedLevelCounts;
        $expectedLevels[ClassifierSemanticLevel::Section->value] = $descriptor->expectedSectionsCount;
        ksort($actualLevels);
        ksort($expectedLevels);

        if ($actualLevels !== $expectedLevels) {
            $this->integrityFailure('candidate_persisted_level_counts_mismatch');
        }

        $invalidParents = DB::table('statistical_classifier_nodes as child')
            ->leftJoin('statistical_classifier_nodes as parent', 'parent.id', '=', 'child.parent_node_id')
            ->where('child.classifier_version_id', $version->id)
            ->whereNotNull('child.parent_node_id')
            ->where(function ($query) use ($version): void {
                $query->whereNull('parent.id')
                    ->orWhere('parent.classifier_version_id', '!=', $version->id);
            })
            ->count();

        if ($invalidParents !== 0) {
            $this->integrityFailure('candidate_persisted_parent_version_mismatch');
        }

        $expectedRoots = $snapshot === null
            ? array_keys(array_filter(
                $descriptor->controlAncestorParents,
                static fn (?string $parentCode): bool => $parentCode === null,
            ))
            : array_values(array_map(
                static fn ($node): string => $node->code,
                array_filter($snapshot->nodes, static fn ($node): bool => $node->parentCode === null),
            ));
        $actualRoots = DB::table('statistical_classifier_nodes')
            ->where('classifier_version_id', $version->id)
            ->whereNull('parent_node_id')
            ->orderBy('source_order')
            ->pluck('code')
            ->all();

        if ($snapshot !== null && $actualRoots !== $expectedRoots) {
            $this->integrityFailure('candidate_persisted_root_set_mismatch');
        }

        $expectedControlParents = $descriptor->controlAncestorParents;
        $expectedControlParents[$descriptor->controlNodeCode] = $descriptor->controlNodeParentCode;
        $controlNodes = DB::table('statistical_classifier_nodes as child')
            ->leftJoin('statistical_classifier_nodes as parent', 'parent.id', '=', 'child.parent_node_id')
            ->where('child.classifier_version_id', $version->id)
            ->whereIn('child.code', array_keys($expectedControlParents))
            ->select([
                'child.code',
                'child.name',
                'child.semantic_level',
                'parent.code as parent_code',
            ])
            ->get()
            ->keyBy('code');

        foreach ($expectedControlParents as $code => $parentCode) {
            $node = $controlNodes->get($code);

            if ($node === null || $node->parent_code !== $parentCode) {
                $this->integrityFailure('candidate_persisted_control_chain_mismatch');
            }
        }

        $control = $controlNodes->get($descriptor->controlNodeCode);

        if ($control === null
            || $control->name !== $descriptor->controlNodeName
            || $control->semantic_level !== $descriptor->controlNodeLevel->value
        ) {
            $this->integrityFailure('candidate_persisted_control_node_mismatch');
        }
    }

    private function isExpectedVersionRace(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062
            && (str_contains($exception->getMessage(), 'stat_cls_versions_classifier_label_unique')
                || str_contains($exception->getMessage(), 'stat_cls_versions_import_unique'));
    }

    /** @return array<string, float|int> */
    private function emptyPerformance(): array
    {
        return [
            'insert_ms' => 0.0,
            'map_ms' => 0.0,
            'parent_update_ms' => 0.0,
            'integrity_ms' => 0.0,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'peak_memory_delta_bytes' => 0,
        ];
    }

    /** @param array<string, float|int> $performance */
    private function result(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifier $classifier,
        StatisticalClassifierSourceFile $source,
        StatisticalClassifierImport $import,
        StatisticalClassifierVersion $version,
        bool $reused,
        float $parseElapsed,
        float $persistenceElapsed,
        int $startedAt,
        array $performance,
    ): ClassifierCandidatePersistenceResult {
        return new ClassifierCandidatePersistenceResult(
            candidateKey: $descriptor->candidateKey,
            classifierCode: $classifier->code,
            classifierPublicId: $classifier->public_id,
            sourcePublicId: $source->public_id,
            sourceSha256: $source->sha256,
            importPublicId: $import->public_id,
            versionPublicId: $version->public_id,
            versionLabel: $version->version_label,
            effectiveFrom: $version->effective_from->toDateString(),
            status: $version->status->value,
            nodeCount: $version->node_count,
            reused: $reused,
            parseElapsedMilliseconds: $parseElapsed,
            persistenceElapsedMilliseconds: $persistenceElapsed,
            totalElapsedMilliseconds: $this->elapsedMilliseconds($startedAt),
            performance: $performance,
        );
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round(max(0, hrtime(true) - $startedAt) / 1_000_000, 3);
    }

    private function integrityFailure(
        string $errorCode,
        string $stage = 'persisted_integrity',
    ): never {
        throw new ClassifierCandidateStagingException(
            $errorCode,
            'The classifier candidate failed transactional persistence integrity validation.',
            $stage,
        );
    }
}
