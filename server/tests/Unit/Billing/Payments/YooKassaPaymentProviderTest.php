<?php

namespace Tests\Unit\Billing\Payments;

use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\User;
use App\Services\Billing\Payments\DTO\PaymentIntentOptions;
use App\Services\Billing\Payments\Providers\YooKassaPaymentProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YooKassaPaymentProviderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_create_payment_intent_sends_yookassa_payload_with_idempotence_key(): void
    {
        $this->configureYooKassa();

        Http::fake([
            'https://api.yookassa.test/v3/payments' => Http::response([
                'id' => '2f0edc2f-000f-5000-8000-18db351245c7',
                'status' => 'pending',
                'confirmation' => [
                    'type' => 'redirect',
                    'confirmation_url' => 'https://yookassa.test/confirm',
                ],
            ], 200),
        ]);

        $invoice = new BillingInvoice([
            'uuid' => 'invoice-uuid',
            'user_id' => 10,
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'description' => 'PrismCore test',
        ]);

        $result = app(YooKassaPaymentProvider::class)->createPaymentIntent($invoice, new PaymentIntentOptions(
            returnUrl: 'https://app.test/billing/return',
            metadata: ['custom' => 'value'],
            idempotencyKey: 'idem-key',
        ));

        $this->assertSame('2f0edc2f-000f-5000-8000-18db351245c7', $result->providerPaymentId);
        $this->assertSame('https://yookassa.test/confirm', $result->confirmationUrl);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.yookassa.test/v3/payments'
                && $request->hasHeader('Idempotence-Key', 'idem-key')
                && $payload['amount']['value'] === '990.00'
                && $payload['amount']['currency'] === 'RUB'
                && $payload['confirmation']['type'] === 'redirect'
                && $payload['confirmation']['return_url'] === 'https://app.test/billing/return'
                && $payload['metadata']['invoice_uuid'] === 'invoice-uuid'
                && $payload['metadata']['user_id'] === '10';
        });
    }

    public function test_get_payment_maps_provider_payload_to_result(): void
    {
        $this->configureYooKassa();

        Http::fake([
            'https://api.yookassa.test/v3/payments/payment-id' => Http::response([
                'id' => 'payment-id',
                'status' => 'succeeded',
                'paid' => true,
                'amount' => [
                    'value' => '990.00',
                    'currency' => 'RUB',
                ],
                'metadata' => [
                    'invoice_uuid' => 'invoice-uuid',
                ],
            ], 200),
        ]);

        $result = app(YooKassaPaymentProvider::class)->getPayment('payment-id');

        $this->assertSame('yookassa', $result->providerCode);
        $this->assertSame('succeeded', $result->status);
        $this->assertTrue($result->paid);
        $this->assertSame(99000, $result->amountMinor);
        $this->assertSame('RUB', $result->currency);
    }

    public function test_create_payment_intent_sends_receipt_when_receipts_enabled(): void
    {
        $this->configureYooKassa(receiptsEnabled: true);

        BillingPlan::query()->create([
            'code' => 'pro_test',
            'name' => 'Pro Test',
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
            ],
        ]);

        $user = User::factory()->create([
            'email' => 'payer@example.test',
        ]);

        Http::fake([
            'https://api.yookassa.test/v3/payments' => Http::response([
                'id' => 'provider-payment-id',
                'status' => 'pending',
            ], 200),
        ]);

        $invoice = new BillingInvoice([
            'uuid' => 'invoice-uuid',
            'user_id' => $user->id,
            'plan_code' => 'pro_test',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'description' => 'PrismCore test',
        ]);

        app(YooKassaPaymentProvider::class)->createPaymentIntent($invoice, new PaymentIntentOptions(
            returnUrl: 'https://app.test/billing/return',
            idempotencyKey: 'idem-key',
        ));

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $item = $payload['receipt']['items'][0] ?? [];

            return ($payload['receipt']['customer']['email'] ?? null) === 'payer@example.test'
                && ($payload['receipt']['internet'] ?? null) === true
                && ($item['description'] ?? null) === 'Подписка PrismCore: Pro Test'
                && ($item['quantity'] ?? null) === '1.00'
                && ($item['amount']['value'] ?? null) === '990.00'
                && ($item['amount']['currency'] ?? null) === 'RUB'
                && ($item['vat_code'] ?? null) === 1
                && ($item['payment_subject'] ?? null) === 'service'
                && ($item['payment_mode'] ?? null) === 'full_prepayment';
        });
    }

    public function test_parse_webhook_extracts_payment_event(): void
    {
        $event = app(YooKassaPaymentProvider::class)->parseWebhook([
            'event' => 'payment.succeeded',
            'object' => [
                'id' => 'payment-id',
                'status' => 'succeeded',
            ],
        ]);

        $this->assertSame('yookassa', $event->providerCode);
        $this->assertSame('payment.succeeded', $event->eventType);
        $this->assertSame('payment-id', $event->providerPaymentId);
    }

    private function configureYooKassa(bool $receiptsEnabled = false): void
    {
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
        config()->set('billing.payments.providers.yookassa.receipts_enabled', $receiptsEnabled);
        config()->set('billing.payments.providers.yookassa.receipt_vat_code', 1);
        config()->set('billing.payments.providers.yookassa.receipt_payment_subject', 'service');
        config()->set('billing.payments.providers.yookassa.receipt_payment_mode', 'full_prepayment');
    }
}
