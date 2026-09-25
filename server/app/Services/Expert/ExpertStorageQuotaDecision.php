<?php

declare(strict_types=1);

namespace App\Services\Expert;

final class ExpertStorageQuotaDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $usedBytes,
        public readonly ?int $limitBytes,
        public readonly int $requestedBytes,
        public readonly int $projectedBytes,
        public readonly ?int $remainingBytes,
        public readonly ?float $usagePercent,
        public readonly int $materialsCount,
        public readonly bool $isUnlimited,
        public readonly bool $isOverLimit,
        public readonly bool $limitAvailable,
        public readonly bool $limitVisible,
        public readonly bool $enforcementEnabled,
        public readonly string $planCode,
        public readonly ?string $reason,
        public readonly int $reservedBytes = 0,
    ) {}

    /** @return array<string, int|float|bool|null> */
    public function toStorageArray(): array
    {
        $visibleLimit = $this->limitVisible && $this->limitAvailable;

        return [
            'used_bytes' => $this->usedBytes,
            'reserved_bytes' => $this->reservedBytes,
            'limit_bytes' => $visibleLimit ? $this->limitBytes : null,
            'remaining_bytes' => $visibleLimit ? $this->remainingBytes : null,
            'usage_percent' => $visibleLimit ? $this->usagePercent : null,
            'materials_count' => $this->materialsCount,
            'is_unlimited' => $visibleLimit && $this->isUnlimited,
            'is_over_limit' => $visibleLimit && $this->isOverLimit,
            'limit_available' => $this->limitAvailable,
            'limit_visible' => $this->limitVisible,
            'enforcement_enabled' => $this->enforcementEnabled,
        ];
    }

    /** @return array<string, int|float|bool|string|null> */
    public function toDecisionArray(): array
    {
        return [
            ...$this->toStorageArray(),
            'allowed' => $this->allowed,
            'requested_bytes' => $this->requestedBytes,
            'projected_bytes' => $this->projectedBytes,
            'plan_code' => $this->planCode,
            'reason' => $this->reason,
        ];
    }
}
