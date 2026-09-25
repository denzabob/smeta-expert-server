<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\BillingGateService;
use OverflowException;

final class ExpertStorageQuotaService
{
    public function __construct(
        private readonly BillingGateService $billingGate,
        private readonly ExpertStorageUsageService $storageUsage,
    ) {}

    public function snapshot(User $user): ExpertStorageQuotaDecision
    {
        return $this->decide(
            $user,
            $this->storageUsage->getUserUsage($user),
            0,
            ['action' => 'expert.storage.snapshot'],
            recordEvent: false,
        );
    }

    /**
     * Decide against a usage value that was read while holding the owner's
     * storage row lock. Callers must make this decision and increment within
     * the same database transaction.
     *
     * @param array<string, mixed> $context
     */
    public function decide(
        User $user,
        ExpertStorageUsageSnapshot $usage,
        int $requestedBytes,
        array $context = [],
        bool $recordEvent = true,
    ): ExpertStorageQuotaDecision {
        if ($requestedBytes < 0) {
            throw new \InvalidArgumentException('Requested storage bytes must not be negative.');
        }
        if ($requestedBytes > PHP_INT_MAX - $usage->usedBytes) {
            throw new OverflowException('Projected expert storage usage exceeds the supported integer range.');
        }

        if ($usage->reservedBytes > PHP_INT_MAX - $usage->usedBytes
            || $requestedBytes > PHP_INT_MAX - $usage->usedBytes - $usage->reservedBytes) {
            throw new OverflowException('Projected expert storage usage exceeds the supported integer range.');
        }

        $accountedBytes = $usage->usedBytes + $usage->reservedBytes;
        $projectedBytes = $accountedBytes + $requestedBytes;
        $gate = $this->billingGate->checkProjectedUsage(
            $user,
            BillingCodes::CAP_STORAGE_BYTES,
            $projectedBytes,
            $context,
            $recordEvent,
        );

        $limitAvailable = ! in_array($gate->reason, ['fail_open', 'exception'], true);
        $limit = $limitAvailable ? $gate->limit : null;
        $isOverLimit = $limit !== null && $accountedBytes > $limit;
        $remaining = $limit === null ? null : max(0, $limit - $accountedBytes);
        $percent = $limit === null
            ? null
            : ($limit === 0 ? ($accountedBytes === 0 ? 0.0 : null) : round(($accountedBytes / $limit) * 100, 2));

        return new ExpertStorageQuotaDecision(
            allowed: $gate->allowed,
            usedBytes: $usage->usedBytes,
            limitBytes: $limit,
            requestedBytes: $requestedBytes,
            projectedBytes: $projectedBytes,
            remainingBytes: $remaining,
            usagePercent: $percent,
            materialsCount: $usage->materialsCount,
            isUnlimited: $limitAvailable && $limit === null,
            isOverLimit: $isOverLimit,
            limitAvailable: $limitAvailable,
            limitVisible: (bool) config('billing.user_ui_enabled', false),
            enforcementEnabled: (bool) config('billing.enforce_limits', false),
            planCode: $gate->planCode,
            reason: $gate->reason,
            reservedBytes: $usage->reservedBytes,
        );
    }
}
