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

class BillingPaymentResultTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_view_own_paid_payment_result(): void
    {
        [$user, $invoice] = $this->makeFixture(status: BillingInvoice::STATUS_PAID, paymentStatus: BillingPayment::STATUS_SUCCEEDED);

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_code' => 'pro_result',
            'status' => 'active',
            'source' => 'payment',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $invoice->forceFill([
            'subscription_id' => $subscription->id,
            'paid_at' => now(),
        ])->save();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/billing/payment-result?invoice_id={$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('title', 'Оплата прошла успешно')
            ->assertJsonPath('invoice.id', $invoice->id)
            ->assertJsonPath('invoice.plan_name', 'Pro Result')
            ->assertJsonPath('subscription.is_active', true)
            ->assertJsonPath('subscription.plan_code', 'pro_result');

        foreach (['provider_payment_id', 'provider_payload', 'raw_payload', 'webhook', 'secret', 'idempotency_key'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
    }

    public function test_user_cannot_view_another_users_invoice_result(): void
    {
        [, $invoice] = $this->makeFixture();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/billing/payment-result?invoice_id={$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('invoice', null);
    }

    public function test_missing_invoice_id_returns_safe_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/payment-result')
            ->assertOk()
            ->assertJsonPath('status', 'not_found')
            ->assertJsonPath('title', 'Платёж не найден');
    }

    public function test_pending_payment_result_attempts_refresh_and_returns_pending(): void
    {
        [$user, $invoice] = $this->makeFixture();
        $this->configureProvider();
        $this->fakeProviderPayment('pending');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/billing/payment-result?invoice_id={$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('invoice.id', $invoice->id);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.yookassa.test/v3/payments/provider-payment-id');
    }

    public function test_paid_result_refresh_activates_subscription_once(): void
    {
        [$user, $invoice] = $this->makeFixture();
        $this->configureProvider();
        $this->fakeProviderPayment('succeeded');

        $first = $this->actingAs($user, 'sanctum')
            ->getJson("/api/billing/payment-result?invoice_id={$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('subscription.is_active', true);

        $subscriptionId = $invoice->refresh()->subscription_id;

        $second = $this->actingAs($user, 'sanctum')
            ->getJson("/api/billing/payment-result?invoice_id={$invoice->id}")
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->assertSame($subscriptionId, $invoice->refresh()->subscription_id);
        $this->assertSame($first->json('subscription.period_ends_at'), $second->json('subscription.period_ends_at'));
        $this->assertSame(1, BillingSubscription::query()->where('user_id', $user->id)->count());
        Http::assertSentCount(1);
    }

    private function makeFixture(
        string $status = BillingInvoice::STATUS_PENDING_PAYMENT,
        string $paymentStatus = BillingPayment::STATUS_PENDING,
    ): array {
        BillingPlan::query()->create([
            'code' => 'pro_result',
            'name' => 'Pro Result',
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
            'plan_code' => 'pro_result',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => $status,
            'description' => 'Result test invoice',
            'metadata_json' => [
                'billing_period' => 'month',
                'source' => 'user_checkout',
            ],
        ]);

        BillingPayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'provider_code' => 'yookassa',
            'provider_payment_id' => 'provider-payment-id',
            'idempotency_key' => (string) Str::uuid(),
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => $paymentStatus,
        ]);

        return [$user, $invoice];
    }

    private function configureProvider(): void
    {
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
    }

    private function fakeProviderPayment(string $status): void
    {
        Http::fake([
            'https://api.yookassa.test/v3/payments/provider-payment-id' => Http::response([
                'id' => 'provider-payment-id',
                'status' => $status,
                'paid' => $status === 'succeeded',
                'amount' => [
                    'value' => '990.00',
                    'currency' => 'RUB',
                ],
            ], 200),
        ]);
    }
}
