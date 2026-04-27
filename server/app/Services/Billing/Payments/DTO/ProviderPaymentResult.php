<?php

namespace App\Services\Billing\Payments\DTO;

class ProviderPaymentResult
{
    public function __construct(
        public string $providerCode,
        public string $providerPaymentId,
        public string $status,
        public bool $paid,
        public int $amountMinor,
        public string $currency,
        public array $metadata,
        public array $rawPayload,
    ) {}
}
