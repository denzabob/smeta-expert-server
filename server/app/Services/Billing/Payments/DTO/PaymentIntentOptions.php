<?php

namespace App\Services\Billing\Payments\DTO;

class PaymentIntentOptions
{
    public function __construct(
        public string $confirmationType = 'redirect',
        public ?string $returnUrl = null,
        public ?string $description = null,
        public array $metadata = [],
        public ?string $idempotencyKey = null,
    ) {}
}
