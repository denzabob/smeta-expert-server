<?php

namespace App\Services\Billing\Payments\Contracts;

use App\Models\BillingInvoice;
use App\Services\Billing\Payments\DTO\PaymentIntentOptions;
use App\Services\Billing\Payments\DTO\PaymentIntentResult;
use App\Services\Billing\Payments\DTO\ProviderPaymentResult;
use App\Services\Billing\Payments\DTO\ProviderWebhookEvent;

interface PaymentProviderInterface
{
    public function code(): string;

    public function createPaymentIntent(
        BillingInvoice $invoice,
        PaymentIntentOptions $options
    ): PaymentIntentResult;

    public function getPayment(string $providerPaymentId): ProviderPaymentResult;

    public function parseWebhook(array $payload, array $headers = []): ProviderWebhookEvent;

    public function supportsRecurring(): bool;
}
