<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Data\ClassifierVersionActivationResult;
use App\Domain\PriceIndices\Application\Services\RollbackStatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierVersionActivationException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class RollbackTrustedClassifier extends Command
{
    protected $signature = 'price-indices:classifier:rollback {candidate-or-version : Explicit trusted candidate key or version public UUID}';

    protected $description = 'Explicitly move a classifier active pointer to an earlier persisted version';

    public function handle(RollbackStatisticalClassifierVersion $rollback): int
    {
        $now = CarbonImmutable::now();

        try {
            $result = $rollback->rollback(
                trim((string) $this->argument('candidate-or-version')),
                $now->startOfDay(),
                $now,
                reason: 'console:price-indices:classifier:rollback',
            );
        } catch (ClassifierCandidateStagingException $exception) {
            $code = $exception->errorCode === 'classifier_candidate_not_supported'
                ? 'unknown_candidate_or_version'
                : $exception->errorCode;
            $this->components->error("[{$code}] {$exception->safeMessage}");

            return SymfonyCommand::FAILURE;
        } catch (ClassifierVersionActivationException $exception) {
            $this->components->error("[{$exception->errorCode}] {$exception->safeMessage}");

            return SymfonyCommand::FAILURE;
        } catch (Throwable) {
            $this->components->error('[rollback_failure] The classifier version could not be restored safely.');

            return SymfonyCommand::FAILURE;
        }

        $this->renderResult($result);

        return SymfonyCommand::SUCCESS;
    }

    private function renderResult(ClassifierVersionActivationResult $result): void
    {
        $status = $result->status === 'activated' ? 'rolled_back' : $result->status;

        $this->table(['Field', 'Value'], [
            ['classifier_code', $result->classifierCode],
            ['classifier_public_id', $result->classifierPublicId],
            ['target_version_public_id', $result->targetVersionPublicId],
            ['target_version_label', $result->targetVersionLabel],
            ['effective_from', $result->effectiveFrom],
            ['nodes', (string) $result->nodeCount],
            ['previous_version_public_id', $result->previousVersionPublicId ?? 'none'],
            ['previous_version_label', $result->previousVersionLabel ?? 'none'],
            ['rollback_status', $status],
        ]);
    }
}
