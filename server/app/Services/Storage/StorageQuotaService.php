<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Models\User;
use App\Services\Billing\BillingGateService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OverflowException;
use Throwable;

class StorageQuotaService
{
    public function __construct(private readonly BillingGateService $billing) {}

    public function limit(int $userId): array
    {
        try {
            return $this->billing->storageLimit(User::query()->findOrFail($userId));
        } catch (Throwable $e) {
            Log::error('Account storage billing limit check failed', ['user_id' => $userId, 'exception' => $e]);
            throw new StorageQuotaException('BILLING_LIMIT_CHECK_FAILED', [], $e);
        }
    }

    public function assertCanUpload(int $userId, int $used, int $reserved, int $requested): void
    {
        $limit = $this->limit($userId)['limit'];
        $projected = self::add(self::add($used, $reserved), $requested);
        if ($limit !== null && ($limit === 0 || $projected > $limit)) {
            $context = ['limit_bytes' => $limit, 'used_bytes' => $used, 'reserved_bytes' => $reserved,
                'requested_bytes' => $requested, 'available_bytes' => max(0, $limit - $used - $reserved)];
            Log::notice('Account storage quota rejected upload', ['user_id' => $userId] + $context);
            throw new StorageQuotaException('STORAGE_QUOTA_EXCEEDED', $context);
        }
    }

    public static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException('Storage bytes must be non-negative.');
        }
        if ($left > PHP_INT_MAX - $right) {
            throw new OverflowException('Storage byte counter overflow.');
        }
        return $left + $right;
    }
}
