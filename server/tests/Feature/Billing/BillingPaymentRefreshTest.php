<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingPaymentRefreshTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_gets_401(): void
    {
        $payment = $this->makeFixture()[2];

        $this->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertUnauthorized();
    }

    public function test_disabled_user_ui_returns_403(): void
    {
        [$user, , $payment] = $this->makeFixture();
        $this->configureBilling(userUiEnabled: false, checkoutEnabled: true);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertForbidden()
            ->assertJsonPath('message', 'Оплата пока недоступна');
    }

    public function test_disabled_checkout_returns_403(): void
    {
        [$user, , $payment] = $this->makeFixture();
        $this->configureBilling(userUiEnabled: true, checkoutEnabled: false);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertForbidden()
            ->assertJsonPath('message', 'Оплата пока недоступна');
    }

    public function test_user_cannot_refresh_another_users_payment(): void
    {
        [, , $payment] = $this->makeFixture();
        $this->configureBilling();
        Http::fake();

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_user_can_refresh_own_pending_payment(): void
    {
        [$user, , $payment] = $this->makeFixture();
        $this->configureBilling();
        $this->fakeProviderPayment('pending');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.amount', 99000)
            ->assertJsonPath('payment.currency', 'RUB')
            ->assertJsonPath('invoice.status', 'pending')
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('message', 'Ожидаем подтверждение оплаты');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.yookassa.test/v3/payments/provider-payment-id');
    }

    public function test_paid_refresh_activates_subscription(): void
    {
        [$user, $invoice, $payment] = $this->makeFixture();
        $this->configureBilling();
        $this->fakeProviderPayment('succeeded');

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.plan_code', 'pro_refresh')
            ->assertJsonPath('message', 'Оплата подтверждена');

        $this->assertNotNull($response->json('subscription.current_period_end'));
        $this->assertNotNull($invoice->refresh()->subscription_id);
        $this->assertDatabaseHas('billing_subscriptions', [
            'id' => $invoice->subscription_id,
            'user_id' => $user->id,
            'plan_code' => 'pro_refresh',
            'status' => 'active',
            'source' => 'payment',
        ]);
    }

    public function test_repeated_refresh_does_not_extend_subscription_twice(): void
    {
        [$user, $invoice, $payment] = $this->makeFixture();
        $this->configureBilling();
        $this->fakeProviderPayment('succeeded');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk();

        $invoice->refresh();
        $subscriptionId = $invoice->subscription_id;
        $periodEnd = $invoice->subscription->current_period_end?->toDateTimeString();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid');

        $invoice->refresh();

        $this->assertSame($subscriptionId, $invoice->subscription_id);
        $this->assertSame($periodEnd, $invoice->subscription->current_period_end?->toDateTimeString());
        Http::assertSentCount(1);
    }

    public function test_canceled_refresh_does_not_activate_subscription(): void
    {
        [$user, $invoice, $payment] = $this->makeFixture();
        $this->configureBilling();
        $this->fakeProviderPayment('canceled', paid: false);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk()
            ->assertJsonPath('payment.status', 'canceled')
            ->assertJsonPath('invoice.status', 'canceled')
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('message', 'Оплата отменена');

        $this->assertNull($invoice->refresh()->subscription_id);
    }

    public function test_failed_refresh_does_not_activate_subscription(): void
    {
        [$user, $invoice, $payment] = $this->makeFixture();
        $this->configureBilling();
        $this->fakeProviderPayment('failed', paid: false);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk()
            ->assertJsonPath('payment.status', 'failed')
            ->assertJsonPath('invoice.status', 'pending')
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('message', 'Оплата не завершена');

        $this->assertNull($invoice->refresh()->subscription_id);
    }

    public function test_safe_response_does_not_expose_provider_internals(): void
    {
        [$user, , $payment] = $this->makeFixture();
        $this->configureBilling();
        $this->fakeProviderPayment('pending', rawExtra: [
            'secret_key' => 'super-secret',
            'metadata' => [
                'provider_payment_id' => 'must-not-leak',
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk();

        foreach (['provider_payment_id', 'provider_payload', 'raw_payload', 'webhook', 'secret', 'idempotency_key', 'receipt', 'yookassa'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_already_paid_local_payment_returns_status_without_duplicate_activation(): void
    {
        [$user, $invoice, $payment] = $this->makeFixture();
        $this->configureBilling();

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_code' => 'pro_refresh',
            'status' => 'active',
            'source' => 'payment',
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addMonth(),
        ]);

        $invoice->forceFill([
            'status' => BillingInvoice::STATUS_PAID,
            'subscription_id' => $subscription->id,
            'paid_at' => now(),
        ])->save();

        $payment->forceFill([
            'status' => BillingPayment::STATUS_SUCCEEDED,
            'succeeded_at' => now(),
        ])->save();

        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/payments/{$payment->id}/refresh")
            ->assertOk()
            ->assertJsonPath('payment.status', 'paid')
            ->assertJsonPath('invoice.status', 'paid')
            ->assertJsonPath('subscription.status', 'active');

        Http::assertNothingSent();
        $this->assertSame($subscription->id, $invoice->refresh()->subscription_id);
        $this->assertSame($subscription->current_period_end?->toDateTimeString(), $invoice->subscription->current_period_end?->toDateTimeString());
    }

    private function makeFixture(): array
    {
        BillingPlan::query()->create([
            'code' => 'pro_refresh',
            'name' => 'Pro Refresh',
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
            ],
        ]);

        $user = User::factory()->create(['role' => 'user']);

        $invoice = BillingInvoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_code' => 'pro_refresh',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingInvoice::STATUS_PENDING_PAYMENT,
            'description' => 'Refresh test invoice',
            'metadata_json' => [
                'billing_period' => 'month',
                'source' => 'user_checkout',
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

    private function configureBilling(bool $userUiEnabled = true, bool $checkoutEnabled = true): void
    {
        config()->set('billing.user_ui_enabled', $userUiEnabled);
        config()->set('billing.checkout_enabled', $checkoutEnabled);
        config()->set('billing.payments.checkout_ui_enabled', $checkoutEnabled);
        config()->set('billing.payments.enabled', true);
        config()->set('billing.payments.providers.yookassa.enabled', true);
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
    }

    private function fakeProviderPayment(
        string $status,
        string $amountValue = '990.00',
        bool $paid = true,
        array $rawExtra = [],
    ): void {
        Http::fake([
            'https://api.yookassa.test/v3/payments/provider-payment-id' => Http::response(array_replace_recursive([
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
            ], $rawExtra), 200),
        ]);
    }
}
