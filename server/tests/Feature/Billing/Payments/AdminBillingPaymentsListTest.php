<?php

namespace Tests\Feature\Billing\Payments;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingProviderEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBillingPaymentsListTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_user_cannot_access_payment_list_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        foreach ([
            '/api/admin/billing/invoices',
            '/api/admin/billing/payments',
            '/api/admin/billing/provider-events',
            '/api/admin/billing/payment-plans',
        ] as $endpoint) {
            $this->actingAs($user, 'sanctum')
                ->getJson($endpoint)
                ->assertForbidden();
        }
    }

    public function test_admin_can_list_invoices_with_filters(): void
    {
        [$admin, $user] = $this->makeUsers();
        $invoice = $this->makeInvoice($user, status: BillingInvoice::STATUS_PENDING_PAYMENT, planCode: 'pro_list');
        $this->makeInvoice(User::factory()->create(['role' => 'user']), status: BillingInvoice::STATUS_PAID, planCode: 'team_list');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/invoices?user_id={$user->id}&status=pending_payment&plan_code=pro_list")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.user.email', $user->email)
            ->assertJsonPath('meta.per_page', 25);
    }

    public function test_admin_can_list_payments_with_filters(): void
    {
        [$admin, $user] = $this->makeUsers();
        $invoice = $this->makeInvoice($user);
        $payment = $this->makePayment($invoice, status: BillingPayment::STATUS_PENDING, providerCode: 'yookassa');
        $this->makePayment($invoice, status: BillingPayment::STATUS_FAILED, providerCode: 'other_provider');

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/payments?invoice_id={$invoice->id}&provider_code=yookassa&status=pending")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.provider_code', 'yookassa');
    }

    public function test_admin_can_list_provider_events_with_filters_without_payload(): void
    {
        [$admin] = $this->makeUsers();

        $event = BillingProviderEvent::query()->create([
            'provider_code' => 'yookassa',
            'event_type' => 'payment.succeeded',
            'provider_object_id' => 'provider-payment-id',
            'provider_payment_id' => 'provider-payment-id',
            'payload' => ['object' => ['id' => 'provider-payment-id']],
            'headers' => ['x-test' => ['1']],
            'processing_status' => BillingProviderEvent::STATUS_FAILED,
            'processing_error' => 'Amount mismatch',
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/provider-events?provider_code=yookassa&processing_status=failed');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.processing_error', 'Amount mismatch');

        $this->assertArrayNotHasKey('payload', $response->json('data.0'));
    }

    public function test_payment_plans_returns_only_plans_with_price_minor(): void
    {
        [$admin] = $this->makeUsers();

        BillingPlan::query()->create([
            'code' => 'paid_visible',
            'name' => 'Paid Visible',
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
            ],
        ]);

        BillingPlan::query()->create([
            'code' => 'no_price_hidden',
            'name' => 'No Price Hidden',
            'is_active' => true,
            'metadata_json' => [],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/payment-plans')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('code');

        $this->assertTrue($codes->contains('paid_visible'));
        $this->assertFalse($codes->contains('no_price_hidden'));
        $response->assertJsonFragment([
            'code' => 'paid_visible',
            'price_minor' => 99000,
            'currency' => 'RUB',
            'billing_period' => 'month',
        ]);
    }

    private function makeUsers(): array
    {
        return [
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'user']),
        ];
    }

    private function makeInvoice(
        User $user,
        string $status = BillingInvoice::STATUS_DRAFT,
        string $planCode = 'pro_list',
    ): BillingInvoice {
        return BillingInvoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_code' => $planCode,
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => $status,
            'description' => 'List test invoice',
            'metadata_json' => [
                'billing_period' => 'month',
            ],
        ]);
    }

    private function makePayment(
        BillingInvoice $invoice,
        string $status,
        string $providerCode,
    ): BillingPayment {
        return BillingPayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider_code' => $providerCode,
            'provider_payment_id' => $providerCode . '-payment-id-' . Str::random(6),
            'idempotency_key' => (string) Str::uuid(),
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
            'status' => $status,
            'confirmation_type' => 'redirect',
            'confirmation_url' => 'https://example.test/confirm',
        ]);
    }
}
