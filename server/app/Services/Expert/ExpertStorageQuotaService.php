<?php

declare(strict_types=1);
namespace App\Services\Expert;

use App\Models\User;
use App\Services\Storage\StorageQuotaService;
use App\Services\Storage\StorageQuotaException;

/** Transitional transport DTO; quota decisions belong to the shared service. */
final class ExpertStorageQuotaService
{
    public function __construct(private readonly StorageQuotaService $quota, private readonly ExpertStorageUsageService $storageUsage) {}

    public function snapshot(User $user): ExpertStorageQuotaDecision
    {
        return $this->decide($user, $this->storageUsage->getUserUsage($user), 0, recordEvent: false);
    }

    public function decide(User $user, ExpertStorageUsageSnapshot $usage, int $requestedBytes, array $context = [], bool $recordEvent = true): ExpertStorageQuotaDecision
    {
        $limit = null;
        $available = true;
        $reason = 'allowed';
        $allowed = true;
        $planCode = '';
        try {
            $resolved = $this->quota->limit((int) $user->id);
            $limit = $resolved['limit'];
            $planCode = $resolved['plan_code'];
            if ($requestedBytes > 0) {
                $this->quota->assertCanUpload((int) $user->id, $usage->usedBytes, $usage->reservedBytes, $requestedBytes);
            }
        } catch (StorageQuotaException $e) {
            $allowed = false;
            $available = $e->errorCode !== 'BILLING_LIMIT_CHECK_FAILED';
            $reason = $available ? 'limit_enforced' : 'exception';
        }
        $total = StorageQuotaService::add($usage->usedBytes, $usage->reservedBytes);
        return new ExpertStorageQuotaDecision(
            allowed: $allowed, usedBytes: $usage->usedBytes, limitBytes: $limit,
            requestedBytes: $requestedBytes, projectedBytes: StorageQuotaService::add($total, $requestedBytes),
            remainingBytes: $limit === null ? null : max(0, $limit - $total),
            usagePercent: $limit === null ? null : ($limit === 0 ? ($total === 0 ? 0.0 : null) : round($total / $limit * 100, 2)),
            materialsCount: $usage->materialsCount, isUnlimited: $available && $limit === null,
            isOverLimit: $limit !== null && $total > $limit, limitAvailable: $available,
            limitVisible: true, enforcementEnabled: true, planCode: $planCode, reason: $reason,
            reservedBytes: $usage->reservedBytes,
        );
    }
}
