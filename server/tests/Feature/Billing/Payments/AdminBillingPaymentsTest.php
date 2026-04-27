<?php

namespace Tests\Feature\Billing\Payments;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminBillingPaymentsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_can_create_invoice_with_amount_from_plan_metadata(): void
    {
        [$admin, $user] = $this->makeUsers();
        $this->makePlan();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/invoices', [
                'user_id' => $user->id,
                'plan_code' => 'pro_test',
                'billing_period' => 'month',
                'amount_minor' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('invoice.amount_minor', 99000)
            ->assertJsonPath('invoice.currency', 'RUB')
            ->assertJsonPath('invoice.status', BillingInvoice::STATUS_DRAFT);

        $this->assertDatabaseHas('billing_invoices', [
            'user_id' => $user->id,
            'plan_code' => 'pro_test',
            'amount_minor' => 99000,
        ]);
    }

    public function test_regular_user_cannot_create_invoice(): void
    {
        [, $user] = $this->makeUsers();
        $this->makePlan();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/billing/invoices', [
                'user_id' => $user->id,
                'plan_code' => 'pro_test',
            ])
            ->assertForbidden();
    }

    public function test_disabled_payments_do_not_call_provider(): void
    {
        [$admin, $user] = $this->makeUsers();
        $this->makePlan();
        $invoice = $this->makeInvoice($user);
        $this->configureYooKassa(paymentsEnabled: false, providerEnabled: true);

        Http::fake();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/invoices/{$invoice->id}/payments", [
                'provider_code' => 'yookassa',
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertDatabaseMissing('billing_payments', [
            'invoice_id' => $invoice->id,
            'provider_payment_id' => 'provider-payment-id',
        ]);
    }

    public function test_admin_can_create_yookassa_payment_with_backend_idempotency_key(): void
    {
        [$admin, $user] = $this->makeUsers();
        $this->makePlan();
        $invoice = $this->makeInvoice($user);
        $this->configureYooKassa();

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

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/invoices/{$invoice->id}/payments", [
                'provider_code' => 'yookassa',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.provider_payment_id', 'provider-payment-id')
            ->assertJsonPath('payment.status', BillingPayment::STATUS_PENDING)
            ->assertJsonPath('payment.confirmation_url', 'https://yookassa.test/confirm');

        $this->assertIsString($response->json('payment.idempotency_key'));
        $this->assertStringNotContainsString('super-secret', $response->getContent());

        Http::assertSent(fn ($request) => $request->hasHeader('Idempotence-Key'));
    }

    public function test_yookassa_payment_with_receipt_requires_customer_email(): void
    {
        [$admin, $user] = $this->makeUsers();
        $user->forceFill(['email' => null])->save();

        $this->makePlan();
        $invoice = $this->makeInvoice($user);
        $this->configureYooKassa(receiptsEnabled: true);

        Http::fake();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/invoices/{$invoice->id}/payments", [
                'provider_code' => 'yookassa',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.payer_email.0', 'Для создания платежа YooKassa с чеком нужен email плательщика');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('billing_payments', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_yookassa_payment_with_receipt_does_not_expose_secret_key(): void
    {
        [$admin, $user] = $this->makeUsers();
        $this->makePlan();
        $invoice = $this->makeInvoice($user);
        $this->configureYooKassa(receiptsEnabled: true);

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

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/invoices/{$invoice->id}/payments", [
                'provider_code' => 'yookassa',
            ])
            ->assertCreated();

        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_invoice_requires_price_in_plan_metadata(): void
    {
        [$admin, $user] = $this->makeUsers();

        BillingPlan::query()->create([
            'code' => 'free_without_price',
            'name' => 'Free without price',
            'is_active' => true,
            'metadata_json' => [],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/invoices', [
                'user_id' => $user->id,
                'plan_code' => 'free_without_price',
            ])
            ->assertUnprocessable();
    }

    private function makeUsers(): array
    {
        return [
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'user']),
        ];
    }

    private function makePlan(): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => 'pro_test',
            'name' => 'Pro Test',
            'is_active' => true,
            'features_json' => [],
            'limits_json' => [],
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
            ],
        ]);
    }

    private function makeInvoice(User $user): BillingInvoice
    {
        return BillingInvoice::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'plan_code' => 'pro_test',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingInvoice::STATUS_DRAFT,
            'description' => 'Test invoice',
            'metadata_json' => [
                'billing_period' => 'month',
                'source' => 'test',
            ],
        ]);
    }

    private function configureYooKassa(bool $paymentsEnabled = true, bool $providerEnabled = true, bool $receiptsEnabled = false): void
    {
        config()->set('billing.payments.enabled', $paymentsEnabled);
        config()->set('billing.payments.providers.yookassa.enabled', $providerEnabled);
        config()->set('billing.payments.providers.yookassa.shop_id', 'shop-id');
        config()->set('billing.payments.providers.yookassa.secret_key', 'super-secret');
        config()->set('billing.payments.providers.yookassa.return_url', 'https://app.test/billing/return');
        config()->set('billing.payments.providers.yookassa.api_base', 'https://api.yookassa.test/v3');
        config()->set('billing.payments.providers.yookassa.receipts_enabled', $receiptsEnabled);
        config()->set('billing.payments.providers.yookassa.receipt_vat_code', 1);
        config()->set('billing.payments.providers.yookassa.receipt_payment_subject', 'service');
        config()->set('billing.payments.providers.yookassa.receipt_payment_mode', 'full_prepayment');
    }
}
