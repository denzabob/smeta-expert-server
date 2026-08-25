<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierVersionActivationResult;
use App\Domain\PriceIndices\Domain\Enums\ClassifierPointerSwitchMode;
use Carbon\CarbonInterface;

class ActivateStatisticalClassifierVersion
{
    public function __construct(
        private readonly ResolveTrustedClassifierCandidateVersion $versions,
        private readonly SwitchStatisticalClassifierActiveVersion $switcher,
    ) {}

    public function activate(
        string $candidateKey,
        CarbonInterface $asOfDate,
        CarbonInterface $activatedAt,
        ?int $activatedBy = null,
        ?string $reason = null,
    ): ClassifierVersionActivationResult {
        $version = $this->versions->resolve($candidateKey);

        return $this->switcher->switchTo(
            $version->public_id,
            $asOfDate,
            $activatedAt,
            ClassifierPointerSwitchMode::Activation,
            $activatedBy,
            $reason,
        );
    }
}
