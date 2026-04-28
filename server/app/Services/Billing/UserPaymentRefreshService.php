<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\Payments\BillingPaymentService;
use App\Services\Billing\Payments\PaymentProviderManager;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserPaymentRefreshService
{
    public function __construct(
        private BillingPaymentService $paymentService,
        private PaymentProviderManager $providerManager,
    ) {}

    public function refresh(User $user, BillingPayment $payment): array
    {
        $this->ensureRefreshEnabled();

        if ((int) $payment->user_id !== (int) $user->id) {
            abort(404);
        }

        $payment->loadMissing(['invoice.subscription']);

        if (! $payment->invoice || (int) $payment->invoice->user_id !== (int) $user->id) {
            abort(404);
        }

        if (! $this->isAlreadyPaid($payment)) {
            $this->providerManager->assertEnabled($payment->provider_code);
            $payment = $this->paymentService->refreshProviderPaymentStatus($payment);
        }

        return $this->payload($payment->refresh()->load(['invoice.subscription']));
    }

    private function ensureRefreshEnabled(): void
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

    private function isAlreadyPaid(BillingPayment $payment): bool
    {
        return $payment->status === BillingPayment::STATUS_SUCCEEDED
            && $payment->invoice?->status === BillingInvoice::STATUS_PAID;
    }

    private function payload(BillingPayment $payment): array
    {
        $invoice = $payment->invoice;
        $subscription = $invoice?->subscription;

        return [
            'payment' => [
                'id' => $payment->id,
                'status' => $this->paymentStatus($payment->status),
                'amount' => $payment->amount_minor,
                'currency' => $payment->currency,
            ],
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'status' => $this->invoiceStatus($invoice->status),
            ] : null,
            'subscription' => $subscription ? $this->subscriptionPayload($subscription) : null,
            'message' => $this->message($payment, $invoice),
        ];
    }

    private function subscriptionPayload(BillingSubscription $subscription): array
    {
        return [
            'status' => $subscription->status,
            'plan_code' => $subscription->plan_code,
            'current_period_end' => $subscription->current_period_end?->toDateTimeString(),
        ];
    }

    private function paymentStatus(string $status): string
    {
        return match ($status) {
            BillingPayment::STATUS_SUCCEEDED => 'paid',
            BillingPayment::STATUS_CANCELED => 'canceled',
            BillingPayment::STATUS_FAILED, BillingPayment::STATUS_REFUNDED => 'failed',
            default => 'pending',
        };
    }

    private function invoiceStatus(string $status): string
    {
        return match ($status) {
            BillingInvoice::STATUS_PAID => 'paid',
            BillingInvoice::STATUS_CANCELED, BillingInvoice::STATUS_EXPIRED => 'canceled',
            BillingInvoice::STATUS_FAILED => 'failed',
            default => 'pending',
        };
    }

    private function message(BillingPayment $payment, ?BillingInvoice $invoice): string
    {
        if ($payment->status === BillingPayment::STATUS_SUCCEEDED && $invoice?->status === BillingInvoice::STATUS_PAID) {
            return 'Оплата подтверждена';
        }

        if ($payment->status === BillingPayment::STATUS_CANCELED) {
            return 'Оплата отменена';
        }

        if (in_array($payment->status, [BillingPayment::STATUS_FAILED, BillingPayment::STATUS_REFUNDED], true)) {
            return 'Оплата не завершена';
        }

        return 'Ожидаем подтверждение оплаты';
    }
}
