<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\User;
use App\Services\Billing\Payments\BillingPaymentService;
use App\Services\Billing\Payments\PaymentProviderManager;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserCheckoutService
{
    public function __construct(
        private BillingPaymentService $paymentService,
        private PaymentProviderManager $providerManager,
    ) {}

    public function createCheckout(User $user, string $planCode): array
    {
        $this->ensureCheckoutEnabled();

        $plan = $this->publicPurchasablePlan($planCode);
        $metadata = $plan->metadata_json ?? [];
        $amountMinor = $this->priceMinor($metadata);
        $currency = strtoupper((string) ($metadata['currency'] ?? 'RUB'));
        $providerCode = (string) config('billing.payments.default_provider', config('billing.provider', 'yookassa'));

        if ($amountMinor <= 0) {
            throw ValidationException::withMessages([
                'plan_code' => 'Этот тариф пока нельзя оплатить.',
            ]);
        }

        if ($currency !== 'RUB') {
            throw ValidationException::withMessages([
                'plan_code' => 'Для оплаты доступна только валюта RUB.',
            ]);
        }

        if ($providerCode !== 'yookassa') {
            throw new RuntimeException('Оплата через выбранного провайдера пока недоступна.');
        }

        $this->providerManager->assertEnabled($providerCode);

        $existingPayment = $this->pendingCheckoutPayment($user, $plan->code);

        if ($existingPayment) {
            return $this->checkoutPayload($existingPayment);
        }

        $invoice = $this->paymentService->createInvoiceForPlan($user, $plan->code, [
            'billing_period' => $metadata['billing_period'] ?? 'month',
            'description' => "PrismCore: тариф {$plan->name}",
            'source' => 'user_checkout',
        ]);

        $payment = $this->paymentService->createPaymentForInvoice($invoice, $providerCode);

        return $this->checkoutPayload($payment);
    }

    private function ensureCheckoutEnabled(): void
    {
        if (
            ! (bool) config('billing.user_ui_enabled', false)
            || ! (bool) config('billing.checkout_enabled', false)
            || ! (bool) config('billing.payments.checkout_ui_enabled', false)
            || ! (bool) config('billing.payments.enabled', false)
        ) {
            throw new HttpException(403, 'Оплата пока недоступна');
        }
    }

    private function publicPurchasablePlan(string $planCode): BillingPlan
    {
        $plan = BillingPlan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();

        if (! $plan || ! $this->isPublicPurchasablePlan($plan)) {
            abort(404);
        }

        return $plan;
    }

    private function isPublicPurchasablePlan(BillingPlan $plan): bool
    {
        $metadata = $plan->metadata_json ?? [];

        return $plan->code !== 'legacy_unlimited'
            && ! (bool) Arr::get($metadata, 'hidden', true)
            && ! (bool) Arr::get($metadata, 'system', false)
            && ! (bool) Arr::get($metadata, 'sandbox', false)
            && ! (bool) Arr::get($metadata, 'internal', false);
    }

    private function pendingCheckoutPayment(User $user, string $planCode): ?BillingPayment
    {
        return BillingPayment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                BillingPayment::STATUS_CREATED,
                BillingPayment::STATUS_PENDING,
                BillingPayment::STATUS_WAITING_FOR_CAPTURE,
            ])
            ->whereNotNull('confirmation_url')
            ->whereHas('invoice', function ($query) use ($planCode) {
                $query
                    ->where('plan_code', $planCode)
                    ->where('status', BillingInvoice::STATUS_PENDING_PAYMENT)
                    ->where('metadata_json->source', 'user_checkout');
            })
            ->with('invoice')
            ->orderByDesc('id')
            ->first();
    }

    private function checkoutPayload(BillingPayment $payment): array
    {
        $payment->loadMissing('invoice');

        return [
            'invoice_id' => $payment->invoice_id,
            'payment_id' => $payment->id,
            'confirmation_url' => $payment->confirmation_url,
        ];
    }

    private function priceMinor(array $metadata): int
    {
        if (! is_numeric($metadata['price_minor'] ?? null)) {
            return 0;
        }

        return (int) $metadata['price_minor'];
    }
}
