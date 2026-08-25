<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Data\ClassifierVersionActivationResult;
use App\Domain\PriceIndices\Application\Services\ActivateStatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierCandidateStagingException;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierVersionActivationException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class ActivateTrustedClassifier extends Command
{
    protected $signature = 'price-indices:classifier:activate {candidate : Persisted trusted classifier candidate key}';

    protected $description = 'Explicitly activate a persisted trusted statistical classifier candidate';

    public function handle(ActivateStatisticalClassifierVersion $activation): int
    {
        $now = CarbonImmutable::now();

        try {
            $result = $activation->activate(
                trim((string) $this->argument('candidate')),
                $now->startOfDay(),
                $now,
                reason: 'console:price-indices:classifier:activate',
            );
        } catch (ClassifierCandidateStagingException $exception) {
            $code = $exception->errorCode === 'classifier_candidate_not_supported'
                ? 'unknown_candidate'
                : $exception->errorCode;
            $this->components->error("[{$code}] {$exception->safeMessage}");

            return SymfonyCommand::FAILURE;
        } catch (ClassifierVersionActivationException $exception) {
            $this->components->error("[{$exception->errorCode}] {$exception->safeMessage}");

            return SymfonyCommand::FAILURE;
        } catch (Throwable) {
            $this->components->error('[activation_failure] The classifier version could not be activated safely.');

            return SymfonyCommand::FAILURE;
        }

        $this->renderResult($result);

        return SymfonyCommand::SUCCESS;
    }

    private function renderResult(ClassifierVersionActivationResult $result): void
    {
        $this->table(['Field', 'Value'], [
            ['classifier_code', $result->classifierCode],
            ['classifier_public_id', $result->classifierPublicId],
            ['target_version_public_id', $result->targetVersionPublicId],
            ['target_version_label', $result->targetVersionLabel],
            ['effective_from', $result->effectiveFrom],
            ['nodes', (string) $result->nodeCount],
            ['previous_version_public_id', $result->previousVersionPublicId ?? 'none'],
            ['previous_version_label', $result->previousVersionLabel ?? 'none'],
            ['activation_status', $result->status],
        ]);
    }
}
