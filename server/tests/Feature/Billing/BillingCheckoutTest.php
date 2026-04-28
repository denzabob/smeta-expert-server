<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_gets_401(): void
    {
        $this->postJson('/api/billing/checkout', [
            'plan_code' => 'pro_month',
        ])->assertUnauthorized();
    }

    public function test_checkout_disabled_returns_403(): void
    {
        $user = User::factory()->create();
        $this->configureBilling(userUiEnabled: true, checkoutEnabled: false);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'pro_month',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Оплата пока недоступна');
    }

    public function test_user_ui_disabled_returns_403(): void
    {
        $user = User::factory()->create();
        $this->configureBilling(userUiEnabled: false, checkoutEnabled: true);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'pro_month',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Оплата пока недоступна');
    }

    public function test_hidden_plan_does_not_create_checkout(): void
    {
        $this->assertPlanCannotBePurchased(['hidden' => true]);
    }

    public function test_system_plan_does_not_create_checkout(): void
    {
        $this->assertPlanCannotBePurchased(['hidden' => false, 'system' => true]);
    }

    public function test_sandbox_plan_does_not_create_checkout(): void
    {
        $this->assertPlanCannotBePurchased(['hidden' => false, 'sandbox' => true]);
    }

    public function test_inactive_plan_does_not_create_checkout(): void
    {
        $user = User::factory()->create();
        $this->configureBilling();
        $this->makePlan('inactive_plan', ['hidden' => false], isActive: false);

        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'inactive_plan',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame(0, BillingPayment::query()->count());
    }

    public function test_free_or_zero_price_plan_does_not_create_provider_payment(): void
    {
        $user = User::factory()->create();
        $this->configureBilling();
        $this->makePlan('free_plan', [
            'hidden' => false,
            'price_minor' => 0,
        ]);

        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'free_plan',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.plan_code.0', 'Этот тариф пока нельзя оплатить.');

        Http::assertNothingSent();
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame(0, BillingPayment::query()->count());
    }

    public function test_public_paid_plan_creates_invoice_and_payment(): void
    {
        $user = User::factory()->create();
        $this->configureBilling();
        $this->makePlan('pro_month', ['hidden' => false]);
        $this->fakeYooKassaPayment();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'pro_month',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'invoice_id',
                'payment_id',
                'confirmation_url',
            ])
            ->assertJsonPath('confirmation_url', 'https://yookassa.test/confirm');

        $this->assertDatabaseHas('billing_invoices', [
            'id' => $response->json('invoice_id'),
            'user_id' => $user->id,
            'plan_code' => 'pro_month',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingInvoice::STATUS_PENDING_PAYMENT,
        ]);
        $this->assertDatabaseHas('billing_payments', [
            'id' => $response->json('payment_id'),
            'invoice_id' => $response->json('invoice_id'),
            'user_id' => $user->id,
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingPayment::STATUS_PENDING,
            'confirmation_url' => 'https://yookassa.test/confirm',
        ]);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.yookassa.test/v3/payments'
            && $request->hasHeader('Idempotence-Key')
            && data_get($request->data(), 'amount.value') === '990.00');
    }

    public function test_response_does_not_expose_provider_internals(): void
    {
        $user = User::factory()->create();
        $this->configureBilling();
        $this->makePlan('pro_month', ['hidden' => false]);
        $this->fakeYooKassaPayment();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'pro_month',
            ])
            ->assertCreated();

        $json = $response->getContent();

        foreach (['provider_payment_id', 'provider_payload', 'raw_payload', 'webhook', 'secret', 'metadata_json', 'receipt'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_repeated_checkout_reuses_existing_pending_payment(): void
    {
        $user = User::factory()->create();
        $this->configureBilling();
        $this->makePlan('pro_month', ['hidden' => false]);
        $this->fakeYooKassaPayment();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'pro_month',
            ])
            ->assertCreated();

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'pro_month',
            ])
            ->assertCreated();

        $this->assertSame($first->json('invoice_id'), $second->json('invoice_id'));
        $this->assertSame($first->json('payment_id'), $second->json('payment_id'));
        $this->assertSame(1, BillingInvoice::query()->count());
        $this->assertSame(1, BillingPayment::query()->count());
        Http::assertSentCount(1);
    }

    private function assertPlanCannotBePurchased(array $metadata): void
    {
        $user = User::factory()->create();
        $this->configureBilling();
        $this->makePlan('blocked_plan', $metadata);

        Http::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan_code' => 'blocked_plan',
            ])
            ->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->assertSame(0, BillingPayment::query()->count());
    }

    private function makePlan(string $code, array $metadata = [], bool $isActive = true): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => 'Pro Month',
            'is_active' => $isActive,
            'metadata_json' => array_replace([
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
                'hidden' => false,
                'sandbox' => false,
                'system' => false,
                'internal' => false,
            ], $metadata),
        ]);
    }

    private function configureBilling(bool $userUiEnabled = true, bool $checkoutEnabled = true): void
    {
        config()->set('billing.user_ui_enabled', $userUiEnabled);
        config()->set('billing.checkout_enabled', $checkoutEnabled);
        config()->set('billing.payments.checkout_ui_enabled', $checkoutEnabled);
        config()->set('billing.payments.enabled', true);
        config()->set('billing.payments.default_provider', 'yookassa');
        config()->set('billing.payments.providers.yookassa.enabled', true);
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.return_url', 'https://app.test/settings/billing?payment_return=1');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
        config()->set('billing.payments.providers.yookassa.receipts_enabled', false);
    }

    private function fakeYooKassaPayment(): void
    {
        Http::fake([
            'https://api.yookassa.test/v3/payments' => Http::response([
                'id' => 'provider-payment-id',
                'status' => 'pending',
                'confirmation' => [
                    'type' => 'redirect',
                    'confirmation_url' => 'https://yookassa.test/confirm',
                ],
            ], 200),
        ]);
    }
}
