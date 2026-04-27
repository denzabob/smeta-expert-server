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

class AdminBillingPaymentRefreshTest extends TestCase
{
    use DatabaseTransactions;

    public function test_refresh_succeeded_pays_invoice_and_activates_subscription(): void
    {
        [$admin, $user, $invoice, $payment] = $this->makeFixture();
        $this->fakeProviderPayment('succeeded');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/payments/{$payment->id}/refresh-provider-status")
            ->assertOk()
            ->assertJsonPath('payment.status', BillingPayment::STATUS_SUCCEEDED)
            ->assertJsonPath('invoice.status', BillingInvoice::STATUS_PAID)
            ->assertJsonPath('subscription.status', 'active');

        $invoice->refresh();

        $this->assertNotNull($invoice->subscription_id);
        $this->assertDatabaseHas('billing_subscriptions', [
            'id' => $invoice->subscription_id,
            'user_id' => $user->id,
            'plan_code' => 'pro_refresh',
            'source' => 'payment',
            'status' => 'active',
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.yookassa.test/v3/payments/provider-payment-id');
    }

    public function test_repeated_refresh_succeeded_does_not_extend_subscription_twice(): void
    {
        [$admin, , $invoice, $payment] = $this->makeFixture();
        $this->fakeProviderPayment('succeeded');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/payments/{$payment->id}/refresh-provider-status")
            ->assertOk();

        $invoice->refresh();
        $subscriptionId = $invoice->subscription_id;
        $periodEnd = $invoice->subscription->current_period_end?->toDateTimeString();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/payments/{$payment->id}/refresh-provider-status")
            ->assertOk();

        $invoice->refresh();

        $this->assertSame($subscriptionId, $invoice->subscription_id);
        $this->assertSame($periodEnd, $invoice->subscription->current_period_end?->toDateTimeString());
    }

    public function test_refresh_canceled_updates_payment_and_invoice(): void
    {
        [$admin, , $invoice, $payment] = $this->makeFixture();
        $this->fakeProviderPayment('canceled', paid: false);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/payments/{$payment->id}/refresh-provider-status")
            ->assertOk()
            ->assertJsonPath('payment.status', BillingPayment::STATUS_CANCELED)
            ->assertJsonPath('invoice.status', BillingInvoice::STATUS_CANCELED);

        $this->assertSame(BillingPayment::STATUS_CANCELED, $payment->refresh()->status);
        $this->assertSame(BillingInvoice::STATUS_CANCELED, $invoice->refresh()->status);
    }

    public function test_amount_mismatch_does_not_pay_invoice(): void
    {
        [$admin, , $invoice, $payment] = $this->makeFixture();
        $this->fakeProviderPayment('succeeded', amountValue: '1.00');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/payments/{$payment->id}/refresh-provider-status")
            ->assertStatus(422);

        $this->assertSame(BillingInvoice::STATUS_PENDING_PAYMENT, $invoice->refresh()->status);
        $this->assertNull($invoice->subscription_id);
    }

    public function test_refresh_response_does_not_include_provider_secret(): void
    {
        [$admin, , , $payment] = $this->makeFixture();
        $this->fakeProviderPayment('pending', rawExtra: [
            'secret_key' => 'super-secret',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/payments/{$payment->id}/refresh-provider-status")
            ->assertOk();

        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('secret_key', $response->getContent());
    }

    private function makeFixture(): array
    {
        $this->configureYooKassa();

        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

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

        return [$admin, $user, $invoice, $payment];
    }

    private function fakeProviderPayment(
        string $status,
        string $amountValue = '990.00',
        bool $paid = true,
        array $rawExtra = [],
    ): void {
        Http::fake([
            'https://api.yookassa.test/v3/payments/provider-payment-id' => Http::response(array_merge([
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

    private function configureYooKassa(): void
    {
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
    }
}
