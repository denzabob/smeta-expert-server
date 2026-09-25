<?php

declare(strict_types=1);

namespace App\Services\Expert;

use RuntimeException;

final class ExpertStorageQuotaException extends RuntimeException
{
    public function __construct(public readonly ExpertStorageQuotaDecision $decision)
    {
        parent::__construct('Expert storage quota rejected the upload.');
    }

    public function toApiResponse(): array
    {
        $billingUnavailable = $this->decision->reason === 'exception';

        return [
            'status' => $billingUnavailable ? 503 : 422,
            'body' => [
                'code' => $billingUnavailable ? 'BILLING_LIMIT_CHECK_FAILED' : 'STORAGE_QUOTA_EXCEEDED',
                'message' => $billingUnavailable
                    ? 'Не удалось проверить лимит хранилища. Повторите попытку позже.'
                    : 'Недостаточно свободного места в хранилище.',
                'storage' => [
                    'used_bytes' => $this->decision->usedBytes,
                    'limit_bytes' => $this->decision->limitBytes,
                    'requested_bytes' => $this->decision->requestedBytes,
                    'projected_bytes' => $this->decision->projectedBytes,
                    'remaining_bytes' => $this->decision->remainingBytes,
                ],
            ],
        ];
    }
}
