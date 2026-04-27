<?php

namespace App\Services\Billing\Payments\Providers;

use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Services\Billing\Payments\Contracts\PaymentProviderInterface;
use App\Services\Billing\Payments\DTO\PaymentIntentOptions;
use App\Services\Billing\Payments\DTO\PaymentIntentResult;
use App\Services\Billing\Payments\DTO\ProviderPaymentResult;
use App\Services\Billing\Payments\DTO\ProviderWebhookEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YooKassaPaymentProvider implements PaymentProviderInterface
{
    public function code(): string
    {
        return 'yookassa';
    }

    public function createPaymentIntent(
        BillingInvoice $invoice,
        PaymentIntentOptions $options
    ): PaymentIntentResult {
        $this->ensureConfigured();

        $payload = [
            'amount' => [
                'value' => $this->formatAmount($invoice->amount_minor),
                'currency' => $invoice->currency,
            ],
            'capture' => true,
            'confirmation' => [
                'type' => $options->confirmationType,
                'return_url' => $options->returnUrl ?: $this->config('return_url'),
            ],
            'description' => $options->description ?: $invoice->description,
            'metadata' => array_merge([
                'invoice_uuid' => $invoice->uuid,
                'user_id' => (string) $invoice->user_id,
            ], $options->metadata),
        ];

        if ($this->receiptsEnabled()) {
            $payload['receipt'] = $this->receiptPayload($invoice);
        }

        $response = Http::withBasicAuth((string) $this->config('shop_id'), (string) $this->config('secret_key'))
            ->withHeaders([
                'Idempotence-Key' => $options->idempotencyKey,
            ])
            ->acceptJson()
            ->asJson()
            ->post($this->apiUrl('payments'), $payload)
            ->throw()
            ->json();

        return new PaymentIntentResult(
            providerCode: $this->code(),
            providerPaymentId: (string) Arr::get($response, 'id'),
            status: (string) Arr::get($response, 'status', 'pending'),
            confirmationType: Arr::get($response, 'confirmation.type'),
            confirmationUrl: Arr::get($response, 'confirmation.confirmation_url'),
            confirmationToken: Arr::get($response, 'confirmation.confirmation_token'),
            rawPayload: $this->sanitizePayload($response),
        );
    }

    public function getPayment(string $providerPaymentId): ProviderPaymentResult
    {
        $this->ensureConfigured();

        $payload = Http::withBasicAuth((string) $this->config('shop_id'), (string) $this->config('secret_key'))
            ->acceptJson()
            ->get($this->apiUrl('payments/' . urlencode($providerPaymentId)))
            ->throw()
            ->json();

        return $this->paymentResultFromPayload($payload);
    }

    public function parseWebhook(array $payload, array $headers = []): ProviderWebhookEvent
    {
        $object = Arr::get($payload, 'object', []);

        return new ProviderWebhookEvent(
            providerCode: $this->code(),
            eventType: (string) Arr::get($payload, 'event', 'unknown'),
            providerObjectId: Arr::get($object, 'id'),
            providerPaymentId: Arr::get($object, 'id'),
            payload: $this->sanitizePayload($payload),
        );
    }

    public function supportsRecurring(): bool
    {
        return false;
    }

    private function paymentResultFromPayload(array $payload): ProviderPaymentResult
    {
        $amountValue = (string) Arr::get($payload, 'amount.value', '0');

        return new ProviderPaymentResult(
            providerCode: $this->code(),
            providerPaymentId: (string) Arr::get($payload, 'id'),
            status: (string) Arr::get($payload, 'status', 'unknown'),
            paid: (bool) Arr::get($payload, 'paid', false),
            amountMinor: $this->minorAmount($amountValue),
            currency: (string) Arr::get($payload, 'amount.currency', 'RUB'),
            metadata: (array) Arr::get($payload, 'metadata', []),
            rawPayload: $this->sanitizePayload($payload),
        );
    }

    private function ensureConfigured(): void
    {
        if (! $this->config('shop_id') || ! $this->config('secret_key')) {
            throw new RuntimeException('YooKassa provider is not configured.');
        }
    }

    private function config(string $key): mixed
    {
        return config("billing.payments.providers.yookassa.$key");
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) $this->config('api_base'), '/') . '/' . ltrim($path, '/');
    }

    private function receiptsEnabled(): bool
    {
        return (bool) $this->config('receipts_enabled');
    }

    private function receiptPayload(BillingInvoice $invoice): array
    {
        $invoice->loadMissing('user');

        return [
            'customer' => [
                'email' => $invoice->user?->email,
            ],
            'items' => [
                [
                    'description' => $this->receiptDescription($invoice),
                    'quantity' => '1.00',
                    'amount' => [
                        'value' => $this->formatAmount($invoice->amount_minor),
                        'currency' => strtoupper((string) $invoice->currency),
                    ],
                    'vat_code' => (int) $this->config('receipt_vat_code'),
                    'payment_subject' => (string) $this->config('receipt_payment_subject'),
                    'payment_mode' => (string) $this->config('receipt_payment_mode'),
                ],
            ],
            'internet' => true,
        ];
    }

    private function receiptDescription(BillingInvoice $invoice): string
    {
        $planName = BillingPlan::query()
            ->where('code', $invoice->plan_code)
            ->value('name');

        if ($planName) {
            return "Подписка PrismCore: {$planName}";
        }

        return $invoice->description ?: "Подписка PrismCore: {$invoice->plan_code}";
    }

    private function formatAmount(int $amountMinor): string
    {
        return number_format($amountMinor / 100, 2, '.', '');
    }

    private function minorAmount(string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function sanitizePayload(array $payload): array
    {
        unset($payload['secret_key'], $payload['authorization']);

        return $payload;
    }
}
