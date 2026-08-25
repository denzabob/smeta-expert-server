<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierCandidateStagingResult;
use App\Domain\PriceIndices\Application\Data\ClassifierImportAllocation;
use App\Domain\PriceIndices\Application\Data\ParsedClassifierSnapshot;
use App\Domain\PriceIndices\Application\Data\TrustedClassifierCandidateDescriptor;
use App\Domain\PriceIndices\Domain\Classifiers\ClassifierImportLifecycle;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierImport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierSourceFile;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierParserException;
use App\Domain\PriceIndices\Infrastructure\Storage\ClassifierArtifactStorage;
use Illuminate\Support\Facades\DB;
use Throwable;

class StageTrustedClassifierCandidate
{
    public function __construct(
        private readonly TrustedClassifierCandidateRegistry $candidates,
        private readonly ResolveTrustedClassifierCandidateSource $sources,
        private readonly ClassifierArtifactStorage $storage,
        private readonly FindEquivalentReadyClassifierImport $readyImports,
        private readonly AllocateClassifierImportAttempt $attempts,
        private readonly ClassifierImportLifecycle $lifecycle,
        private readonly ParseOkpd2ClassifierArtifact $parser,
        private readonly ValidateClassifierCandidateSnapshot $validator,
    ) {}

    public function stage(string $candidateKey): ClassifierCandidateStagingResult
    {
        $startedAt = hrtime(true);
        $descriptor = $this->candidates->get($candidateKey);
        [$classifier, $source] = $this->sources->resolve($descriptor);
        $this->verifyArtifact($descriptor, $source);

        $existingVersion = $this->findExistingVersion($descriptor, $classifier, $source);

        if ($existingVersion !== null) {
            return $this->result(
                $descriptor,
                $classifier,
                $source,
                $existingVersion->classifierImport,
                true,
                $startedAt,
                $existingVersion->public_id,
            );
        }

        $allocation = $this->attempts->allocate($descriptor, $classifier, $source);

        if ($allocation->reused) {
            return $this->result($descriptor, $classifier, $source, $allocation->import, true, $startedAt);
        }

        $import = $allocation->import;
        $this->lifecycle->transition($import, ClassifierImportStatus::Parsing)->save();

        try {
            $identity = $this->parser->identity();

            if ($identity->code !== $descriptor->parserCode || $identity->version !== $descriptor->parserVersion) {
                throw new ClassifierCandidateStagingException(
                    'candidate_parser_identity_mismatch',
                    'The registered parser implementation does not match the trusted candidate descriptor.',
                    'parsing',
                );
            }

            // Block 2C remains generic. Candidate-exact checks run only after parsing.
            $snapshot = $this->parser->parse($source);
        } catch (ClassifierParserException $exception) {
            $controlled = new ClassifierCandidateStagingException(
                $exception->errorCode,
                'The classifier artifact could not be parsed safely.',
                'parsing',
                [
                    'parser_code' => $descriptor->parserCode,
                    'parser_version' => $descriptor->parserVersion,
                ],
                $exception,
            );
            $this->fail($import, $controlled);

            throw $controlled;
        } catch (ClassifierCandidateStagingException $exception) {
            $this->fail($import, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $controlled = new ClassifierCandidateStagingException(
                'classifier_candidate_parsing_failed',
                'The classifier artifact could not be parsed safely.',
                'parsing',
                [
                    'parser_code' => $descriptor->parserCode,
                    'parser_version' => $descriptor->parserVersion,
                ],
                $exception,
            );
            $this->fail($import, $controlled);

            throw $controlled;
        }

        $this->lifecycle->transition($import, ClassifierImportStatus::Validating)->save();

        try {
            $metrics = $this->validator->validate($descriptor, $source, $snapshot);
        } catch (ClassifierCandidateStagingException $exception) {
            $this->fail($import, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $controlled = new ClassifierCandidateStagingException(
                'classifier_candidate_validation_failed',
                'The parsed classifier candidate could not be validated safely.',
                'validating',
                [],
                $exception,
            );
            $this->fail($import, $controlled);

            throw $controlled;
        }

        $summary = $this->validationSummary($descriptor, $metrics);
        $final = $this->finalizeReady($descriptor, $source, $import, $snapshot, $summary);

        return $this->result(
            $descriptor,
            $classifier,
            $source,
            $final->import,
            $final->reused,
            $startedAt,
        );
    }

    private function verifyArtifact(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifierSourceFile $source,
    ): void {
        if (! hash_equals($descriptor->sourceSha256, strtolower($source->sha256))) {
            $this->integrityFailure();
        }

        try {
            $this->storage->verify(
                $source->storage_disk,
                $source->storage_path,
                $source->sha256,
                $source->size_bytes,
            );
        } catch (ClassifierAcquisitionException $exception) {
            throw new ClassifierCandidateStagingException(
                'source_artifact_integrity_failure',
                'The exact classifier source artifact failed immutable integrity verification.',
                'artifact_integrity',
                [],
                $exception,
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

    private function integrityFailure(): never
    {
        throw new ClassifierCandidateStagingException(
            'source_artifact_integrity_failure',
            'The exact classifier source artifact failed immutable integrity verification.',
            'artifact_integrity',
        );
    }

    private function findExistingVersion(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifier $classifier,
        StatisticalClassifierSourceFile $source,
    ): ?StatisticalClassifierVersion {
        $version = StatisticalClassifierVersion::query()
            ->with('classifierImport')
            ->where('classifier_id', $classifier->id)
            ->where('version_label', $descriptor->versionLabel)
            ->first();

        if ($version === null) {
            return null;
        }

        $import = $version->classifierImport;
        $matches = $version->effective_from?->toDateString() === $descriptor->effectiveFrom
            && $import instanceof StatisticalClassifierImport
            && $import->status === ClassifierImportStatus::Ready
            && $import->source_file_id === $source->id
            && $import->parser_code === $descriptor->parserCode
            && $import->parser_version === (string) $descriptor->parserVersion
            && $this->readyImports->hasCandidateProvenance($import, $descriptor);

        if (! $matches) {
            throw new ClassifierCandidateStagingException(
                'candidate_version_conflict',
                'An existing classifier version label has conflicting candidate provenance.',
                'version_preflight',
                ['version_label' => $descriptor->versionLabel],
            );
        }

        return $version;
    }

    /**
     * @param  array<string, int|array<string, int>>  $metrics
     * @return array<string, mixed>
     */
    private function validationSummary(
        TrustedClassifierCandidateDescriptor $descriptor,
        array $metrics,
    ): array {
        return [
            'candidate_key' => $descriptor->candidateKey,
            'candidate_fingerprint' => $descriptor->fingerprint(),
            'version_label' => $descriptor->versionLabel,
            'effective_from' => $descriptor->effectiveFrom,
            'source' => ['sha256' => $descriptor->sourceSha256],
            'parser' => [
                'code' => $descriptor->parserCode,
                'version' => $descriptor->parserVersion,
            ],
            'metrics' => [
                'sections_count' => $metrics['sections_count'],
                'digital_nodes_count' => $metrics['digital_nodes_count'],
                'total_nodes_count' => $metrics['total_nodes_count'],
                'warnings_count' => $metrics['warnings_count'],
            ],
            'level_counts' => $metrics['level_counts'],
            'notes_count' => $metrics['notes_count'],
        ];
    }

    /** @param array<string, mixed> $summary */
    private function finalizeReady(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifierSourceFile $source,
        StatisticalClassifierImport $import,
        ParsedClassifierSnapshot $snapshot,
        array $summary,
    ): ClassifierImportAllocation {
        return DB::transaction(function () use ($descriptor, $source, $import, $snapshot, $summary): ClassifierImportAllocation {
            $lockedSource = StatisticalClassifierSourceFile::query()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedImport = StatisticalClassifierImport::query()
                ->whereKey($import->id)
                ->lockForUpdate()
                ->firstOrFail();
            $winner = $this->readyImports->find($descriptor, $lockedSource, $lockedImport->id);

            if ($winner !== null) {
                $race = new ClassifierCandidateStagingException(
                    'equivalent_ready_import_won_race',
                    'An equivalent classifier import became ready first.',
                    'validating',
                    ['candidate_key' => $descriptor->candidateKey],
                );
                $this->fail($lockedImport, $race, 0);

                return new ClassifierImportAllocation($winner, true);
            }

            $this->lifecycle->transition($lockedImport, ClassifierImportStatus::Ready);
            $lockedImport->fill([
                'nodes_parsed' => $snapshot->totalNodesCount,
                'sections_count' => $snapshot->sectionsCount,
                'validation_errors_count' => 0,
                'validation_warnings_count' => count($snapshot->warnings),
                'validation_summary_json' => $summary,
                'error_json' => null,
            ]);
            $lockedImport->save();

            return new ClassifierImportAllocation($lockedImport, false);
        }, 3);
    }

    private function fail(
        StatisticalClassifierImport $import,
        ClassifierCandidateStagingException $exception,
        int $validationErrors = 1,
    ): void {
        $this->lifecycle->transition($import, ClassifierImportStatus::Failed);
        $import->fill([
            'validation_errors_count' => $validationErrors,
            'error_json' => [
                'error_code' => $exception->errorCode,
                'safe_message' => $exception->safeMessage,
                'stage' => $exception->stage,
                'context' => $exception->boundedContext,
            ],
        ]);
        $import->save();
    }

    private function result(
        TrustedClassifierCandidateDescriptor $descriptor,
        StatisticalClassifier $classifier,
        StatisticalClassifierSourceFile $source,
        StatisticalClassifierImport $import,
        bool $reused,
        int $startedAt,
        ?string $versionPublicId = null,
    ): ClassifierCandidateStagingResult {
        $summary = is_array($import->validation_summary_json) ? $import->validation_summary_json : [];
        $metrics = is_array($summary['metrics'] ?? null) ? $summary['metrics'] : [];
        $levelCounts = is_array($summary['level_counts'] ?? null) ? $summary['level_counts'] : [];

        return new ClassifierCandidateStagingResult(
            candidateKey: $descriptor->candidateKey,
            candidateFingerprint: $descriptor->fingerprint(),
            versionLabel: $descriptor->versionLabel,
            classifierCode: $classifier->code,
            classifierPublicId: $classifier->public_id,
            sourcePublicId: $source->public_id,
            sourceSha256: $source->sha256,
            importPublicId: $import->public_id,
            attempt: $import->attempt,
            parserCode: $import->parser_code,
            parserVersion: (int) $import->parser_version,
            status: $import->status->value,
            metrics: [
                'sections_count' => (int) ($metrics['sections_count'] ?? 0),
                'digital_nodes_count' => (int) ($metrics['digital_nodes_count'] ?? 0),
                'total_nodes_count' => (int) ($metrics['total_nodes_count'] ?? 0),
                'warnings_count' => (int) ($metrics['warnings_count'] ?? 0),
                'notes_count' => (int) ($summary['notes_count'] ?? 0),
                'level_counts' => array_map('intval', $levelCounts),
            ],
            reused: $reused,
            elapsedMilliseconds: round(max(0, hrtime(true) - $startedAt) / 1_000_000, 3),
            versionPublicId: $versionPublicId,
        );
    }
}
