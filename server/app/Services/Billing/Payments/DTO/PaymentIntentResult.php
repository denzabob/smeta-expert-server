<?php

namespace App\Services\Billing\Payments\DTO;

class PaymentIntentResult
{
    public function __construct(
        public string $providerCode,
        public string $providerPaymentId,
        public string $status,
        public ?string $confirmationType,
        public ?string $confirmationUrl,
        public ?string $confirmationToken,
        public array $rawPayload,
    ) {}
}
