<?php

namespace Tests\Feature\Billing\Payments;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class YooKassaWebhookTest extends TestCase
{
    use DatabaseTransactions;

    public function test_succeeded_webhook_saves_event_pays_invoice_and_activates_subscription(): void
    {
        [$user, $invoice, $payment] = $this->makePaymentFixture();
        $this->fakeProviderPayment('succeeded');

        $this->postJson('/api/billing/webhooks/yookassa', $this->webhookPayload('payment.succeeded', $payment->provider_payment_id))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $invoice->refresh();

        $this->assertSame(BillingInvoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNotNull($invoice->subscription_id);
        $this->assertDatabaseHas('billing_subscriptions', [
            'id' => $invoice->subscription_id,
            'user_id' => $user->id,
            'plan_code' => 'pro_test',
            'status' => 'active',
            'source' => 'payment',
        ]);
        $this->assertDatabaseHas('billing_provider_events', [
            'provider_code' => 'yookassa',
            'event_type' => 'payment.succeeded',
            'processing_status' => 'processed',
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.yookassa.test/v3/payments/' . $payment->provider_payment_id);
    }

    public function test_repeated_succeeded_webhook_does_not_extend_subscription_twice(): void
    {
        [, $invoice, $payment] = $this->makePaymentFixture();
        $this->fakeProviderPayment('succeeded');

        $this->postJson('/api/billing/webhooks/yookassa', $this->webhookPayload('payment.succeeded', $payment->provider_payment_id))
            ->assertOk();

        $invoice->refresh();
        $subscriptionId = $invoice->subscription_id;
        $periodEnd = $invoice->subscription->current_period_end?->toDateTimeString();

        $this->postJson('/api/billing/webhooks/yookassa', $this->webhookPayload('payment.succeeded', $payment->provider_payment_id))
            ->assertOk();

        $invoice->refresh();

        $this->assertSame($subscriptionId, $invoice->subscription_id);
        $this->assertSame($periodEnd, $invoice->subscription->current_period_end?->toDateTimeString());
    }

    public function test_amount_mismatch_marks_provider_event_failed_and_does_not_pay_invoice(): void
    {
        [, $invoice, $payment] = $this->makePaymentFixture();
        $this->fakeProviderPayment('succeeded', amountValue: '1.00');

        $this->postJson('/api/billing/webhooks/yookassa', $this->webhookPayload('payment.succeeded', $payment->provider_payment_id))
            ->assertStatus(500);

        $this->assertSame(BillingInvoice::STATUS_PENDING_PAYMENT, $invoice->refresh()->status);
        $this->assertDatabaseHas('billing_provider_events', [
            'provider_payment_id' => $payment->provider_payment_id,
            'processing_status' => 'failed',
        ]);
    }

    public function test_canceled_webhook_cancels_payment_and_invoice(): void
    {
        [, $invoice, $payment] = $this->makePaymentFixture();
        $this->fakeProviderPayment('canceled', paid: false);

        $this->postJson('/api/billing/webhooks/yookassa', $this->webhookPayload('payment.canceled', $payment->provider_payment_id))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        $this->assertSame(BillingPayment::STATUS_CANCELED, $payment->refresh()->status);
        $this->assertSame(BillingInvoice::STATUS_CANCELED, $invoice->refresh()->status);
    }

    private function makePaymentFixture(): array
    {
        $this->configureYooKassa();

        $user = User::factory()->create(['role' => 'user']);

        BillingPlan::query()->create([
            'code' => 'pro_test',
            'name' => 'Pro Test',
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
            ],
        ]);

        $invoice = BillingInvoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_code' => 'pro_test',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingInvoice::STATUS_PENDING_PAYMENT,
            'description' => 'Test invoice',
            'metadata_json' => [
                'billing_period' => 'month',
                'source' => 'test',
            ],
        ]);

        $payment = BillingPayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'provider_code' => 'yookassa',
            'provider_payment_id' => 'provider-payment-id',
            'idempotency_key' => (string) Str::uuid(),
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingPayment::STATUS_PENDING,
        ]);

        return [$user, $invoice, $payment];
    }

    private function fakeProviderPayment(string $status, string $amountValue = '990.00', bool $paid = true): void
    {
        Http::fake([
            'https://api.yookassa.test/v3/payments/provider-payment-id' => Http::response([
                'id' => 'provider-payment-id',
                'status' => $status,
                'paid' => $paid,
                'amount' => [
                    'value' => $amountValue,
                    'currency' => 'RUB',
                ],
                'metadata' => [
                    'invoice_uuid' => 'invoice-uuid',
                ],
            ], 200),
        ]);
    }

    private function webhookPayload(string $eventType, string $providerPaymentId): array
    {
        return [
            'type' => 'notification',
            'event' => $eventType,
            'object' => [
                'id' => $providerPaymentId,
                'status' => str_replace('payment.', '', $eventType),
            ],
        ];
    }

    private function configureYooKassa(): void
    {
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
    }
}
