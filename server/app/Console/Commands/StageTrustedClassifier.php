<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Services\PersistTrustedClassifierCandidate;
use App\Domain\PriceIndices\Application\Services\StageTrustedClassifierCandidate;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class StageTrustedClassifier extends Command
{
    protected $signature = 'price-indices:classifier:stage {candidate : Trusted classifier candidate key}';

    protected $description = 'Stage and persist a trusted statistical classifier candidate without activating it';

    public function handle(
        StageTrustedClassifierCandidate $staging,
        PersistTrustedClassifierCandidate $persistence,
    ): int {
        $startedAt = hrtime(true);
        $candidateKey = trim((string) $this->argument('candidate'));
        $asOfDate = CarbonImmutable::now()->startOfDay();

        try {
            $staged = $staging->stage($candidateKey);
            $persisted = $persistence->persist($candidateKey, $asOfDate);
        } catch (ClassifierCandidateStagingException $exception) {
            $this->components->error(
                '['.$this->publicErrorCode($exception)."] {$exception->safeMessage}"
            );

            return SymfonyCommand::FAILURE;
        } catch (Throwable) {
            $this->components->error(
                '[persistence_failure] The classifier candidate could not be staged safely.'
            );

            return SymfonyCommand::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['candidate_key', $persisted->candidateKey],
            ['classifier_code', $persisted->classifierCode],
            ['classifier_public_id', $persisted->classifierPublicId],
            ['source_artifact_public_id', $persisted->sourcePublicId],
            ['source_sha256', $persisted->sourceSha256],
            ['import_public_id', $persisted->importPublicId],
            ['import_attempt', (string) $staged->attempt],
            ['import_result', $staged->reused ? 'reused' : 'new'],
            ['parser_code', $staged->parserCode],
            ['parser_version', (string) $staged->parserVersion],
            ['version_public_id', $persisted->versionPublicId],
            ['version_label', $persisted->versionLabel],
            ['effective_from', $persisted->effectiveFrom],
            ['version_status', $persisted->status],
            ['version_result', $persisted->reused ? 'reused' : 'new'],
            ['snapshot_nodes', (string) $persisted->nodeCount],
            ['snapshot_sections', (string) ($staged->metrics['sections_count'] ?? 0)],
            ['snapshot_notes', (string) ($staged->metrics['notes_count'] ?? 0)],
            ['snapshot_warnings', (string) ($staged->metrics['warnings_count'] ?? 0)],
            ['staging_elapsed_ms', $this->milliseconds($staged->elapsedMilliseconds)],
            ['parse_elapsed_ms', $this->milliseconds($persisted->parseElapsedMilliseconds)],
            ['persistence_elapsed_ms', $this->milliseconds($persisted->persistenceElapsedMilliseconds)],
            ['total_elapsed_ms', $this->milliseconds((hrtime(true) - $startedAt) / 1_000_000)],
        ]);

        return SymfonyCommand::SUCCESS;
    }

    private function publicErrorCode(ClassifierCandidateStagingException $exception): string
    {
        if ($exception->errorCode === 'classifier_candidate_not_supported') {
            return 'unknown_candidate';
        }

        if (in_array($exception->errorCode, [
            'source_artifact_not_available',
            'source_artifact_integrity_failure',
            'candidate_version_conflict',
            'candidate_import_not_ready',
        ], true)) {
            return $exception->errorCode;
        }

        if ($exception->stage === 'validating'
            || $exception->stage === 'candidate_validation'
            || str_contains($exception->errorCode, 'validation')
        ) {
            return 'candidate_validation_failure';
        }

        return str_contains($exception->stage, 'persist')
            || str_contains($exception->stage, 'version')
            ? 'persistence_failure'
            : $exception->errorCode;
    }

    private function milliseconds(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
