<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Services\AcquireTrustedClassifierArtifact;
use App\Domain\PriceIndices\Application\Services\TrustedClassifierCandidateRegistry;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierAcquisitionException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class AcquireTrustedClassifier extends Command
{
    protected $signature = 'price-indices:classifier:acquire {classifier : Trusted classifier code}';

    protected $description = 'Acquire a trusted statistical classifier source artifact';

    public function handle(
        AcquireTrustedClassifierArtifact $acquisition,
        TrustedClassifierCandidateRegistry $candidates,
    ): int {
        $startedAt = hrtime(true);

        try {
            $result = $acquisition->acquire((string) $this->argument('classifier'));
        } catch (ClassifierAcquisitionException $exception) {
            $this->components->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return SymfonyCommand::FAILURE;
        }

        $sourceFile = $result->sourceFile;
        $metadata = $sourceFile->metadata_json ?? [];
        $candidateKey = $candidates->findMatchingCandidateKey(
            $result->classifier->code,
            $sourceFile->declared_version_label,
            $sourceFile->sha256,
        );
        $rows = [
            ['classifier_code', $result->classifier->code],
            ['source', 'Rosstat'],
            ['version', $sourceFile->declared_version_label ?? 'unknown'],
            ['artifact_type', $metadata['artifact_type'] ?? 'unknown'],
            ['candidate', $candidateKey ?? 'unavailable'],
            ['classifier_public_id', $result->classifier->public_id],
            ['source_artifact_public_id', $sourceFile->public_id],
            ['resolved_url', $result->resolvedUrl],
            ['size_bytes', (string) $sourceFile->size_bytes],
            ['sha256', $sourceFile->sha256],
        ];

        if ($sourceFile->etag !== null) {
            $rows[] = ['etag', $sourceFile->etag];
        }

        if ($sourceFile->last_modified_at !== null) {
            $rows[] = ['last_modified_at', $sourceFile->last_modified_at->toIso8601String()];
        }

        $rows[] = ['downloaded_at', $sourceFile->downloaded_at?->toIso8601String() ?? 'unknown'];
        $rows[] = ['result', $result->reused ? 'reused' : 'new'];
        $rows[] = ['elapsed_ms', number_format((hrtime(true) - $startedAt) / 1_000_000, 2, '.', '')];

        $this->table(['Field', 'Value'], $rows);

        return SymfonyCommand::SUCCESS;
    }
}
