<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierVersionActivationResult;
use App\Domain\PriceIndices\Domain\Enums\ClassifierPointerSwitchMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class RollbackStatisticalClassifierVersion
{
    public function __construct(
        private readonly ResolveTrustedClassifierCandidateVersion $versions,
        private readonly SwitchStatisticalClassifierActiveVersion $switcher,
    ) {}

    public function rollback(
        string $candidateOrVersion,
        CarbonInterface $asOfDate,
        CarbonInterface $activatedAt,
        ?int $activatedBy = null,
        ?string $reason = null,
    ): ClassifierVersionActivationResult {
        $targetPublicId = Str::isUuid($candidateOrVersion)
            ? $candidateOrVersion
            : $this->versions->resolve($candidateOrVersion)->public_id;

        return $this->switcher->switchTo(
            $targetPublicId,
            $asOfDate,
            $activatedAt,
            ClassifierPointerSwitchMode::Rollback,
            $activatedBy,
            $reason,
        );
    }
}
