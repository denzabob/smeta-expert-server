<?php

namespace App\Services\Billing\Payments\DTO;

class ProviderWebhookEvent
{
    public function __construct(
        public string $providerCode,
        public string $eventType,
        public ?string $providerObjectId,
        public ?string $providerPaymentId,
        public array $payload,
    ) {}
}
