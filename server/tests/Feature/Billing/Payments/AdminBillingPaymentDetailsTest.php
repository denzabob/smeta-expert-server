<?php

namespace Tests\Feature\Billing\Payments;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingProviderEvent;
use App\Models\BillingSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminBillingPaymentDetailsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_user_cannot_access_detail_endpoints(): void
    {
        [$admin, $user, $invoice, $payment, $event] = $this->makeFixture();

        foreach ([
            "/api/admin/billing/invoices/{$invoice->id}/details",
            "/api/admin/billing/payments/{$payment->id}/details",
            "/api/admin/billing/provider-events/{$event->id}/details",
        ] as $endpoint) {
            $this->actingAs($user, 'sanctum')
                ->getJson($endpoint)
                ->assertForbidden();
        }
    }

    public function test_admin_can_open_invoice_details(): void
    {
        [$admin, , $invoice, $payment] = $this->makeFixture(withSubscription: true);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/invoices/{$invoice->id}/details")
            ->assertOk()
            ->assertJsonPath('invoice.id', $invoice->id)
            ->assertJsonPath('payments.0.id', $payment->id)
            ->assertJsonPath('subscription.status', 'active');
    }

    public function test_admin_can_open_payment_details_with_sanitized_payload(): void
    {
        [$admin, , , $payment] = $this->makeFixture();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/payments/{$payment->id}/details")
            ->assertOk()
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.provider_payload.visible', 'ok');

        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('4111111111111111', $response->getContent());
    }

    public function test_admin_can_open_provider_event_details_with_sanitized_payload_and_headers(): void
    {
        [$admin, , , $payment, $event] = $this->makeFixture();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/provider-events/{$event->id}/details")
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('event.payload.object.id', $payment->provider_payment_id);

        $this->assertStringNotContainsString('Bearer hidden-token', $response->getContent());
        $this->assertStringNotContainsString('123', $response->getContent());
    }

    private function makeFixture(bool $withSubscription = false): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        $invoice = BillingInvoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_code' => 'pro_details',
            'amount_minor' => 99000,
            'currency' => 'RUB',
            'status' => BillingInvoice::STATUS_PENDING_PAYMENT,
            'description' => 'Details test invoice',
            'metadata_json' => [
                'billing_period' => 'month',
            ],
        ]);

        if ($withSubscription) {
            $subscription = BillingSubscription::query()->create([
                'user_id' => $user->id,
                'plan_code' => 'pro_details',
                'status' => 'active',
                'source' => 'payment',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);

            $invoice->forceFill([
                'subscription_id' => $subscription->id,
                'status' => BillingInvoice::STATUS_PAID,
                'paid_at' => now(),
            ])->save();
        }

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
            'provider_payload' => [
                'visible' => 'ok',
                'secret_key' => 'super-secret',
                'payment_method' => [
                    'card_number' => '4111111111111111',
                    'card_last4' => '1111',
                ],
            ],
        ]);

        $event = BillingProviderEvent::query()->create([
            'provider_code' => 'yookassa',
            'event_type' => 'payment.succeeded',
            'provider_object_id' => 'provider-payment-id',
            'provider_payment_id' => 'provider-payment-id',
            'payload' => [
                'object' => [
                    'id' => 'provider-payment-id',
                    'secret' => '123',
                ],
            ],
            'headers' => [
                'Authorization' => ['Bearer hidden-token'],
                'x-visible' => ['ok'],
            ],
            'processing_status' => BillingProviderEvent::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        return [$admin, $user, $invoice, $payment, $event];
    }
}
