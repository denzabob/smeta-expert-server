<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;
use Throwable;

final class StorageQuotaException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
    }

    public function toApiResponse(): array
    {
        $context = $this->context;
        if (array_key_exists('available_bytes', $context)) {
            $context['remaining_bytes'] = $context['available_bytes'];
        }
        if (isset($context['used_bytes'], $context['reserved_bytes'], $context['requested_bytes'])) {
            $context['projected_bytes'] = StorageQuotaService::add(
                StorageQuotaService::add($context['used_bytes'], $context['reserved_bytes']),
                $context['requested_bytes'],
            );
        }
        return ['status' => $this->errorCode === 'BILLING_LIMIT_CHECK_FAILED' ? 503 : 422, 'body' => [
            'code' => $this->errorCode,
            'message' => $this->errorCode === 'BILLING_LIMIT_CHECK_FAILED'
                ? 'Не удалось проверить лимит хранилища.' : 'Недостаточно свободного места в хранилище.',
            'storage' => $context,
        ] + $context];
    }
}
