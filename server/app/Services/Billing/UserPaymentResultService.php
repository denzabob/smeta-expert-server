<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\User;
use App\Services\Billing\Payments\BillingPaymentService;
use Throwable;

class UserPaymentResultService
{
    public function __construct(
        private BillingPaymentService $paymentService,
    ) {}

    public function result(User $user, ?int $invoiceId): array
    {
        if (! $invoiceId) {
            return $this->notFoundPayload();
        }

        $invoice = BillingInvoice::query()
            ->with(['payments' => fn ($query) => $query->orderByDesc('id'), 'subscription'])
            ->whereKey($invoiceId)
            ->where('user_id', $user->id)
            ->first();

        if (! $invoice) {
            return $this->notFoundPayload();
        }

        $payment = $invoice->payments->first();

        if ($payment && $this->shouldRefresh($payment)) {
            try {
                $this->paymentService->refreshProviderPaymentStatus($payment);
                $invoice = $invoice->refresh()->load(['payments' => fn ($query) => $query->orderByDesc('id'), 'subscription']);
                $payment = $invoice->payments->first();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->payload($invoice, $payment);
    }

    private function shouldRefresh(BillingPayment $payment): bool
    {
        return $payment->provider_payment_id
            && in_array($payment->status, [
                BillingPayment::STATUS_CREATED,
                BillingPayment::STATUS_PENDING,
                BillingPayment::STATUS_WAITING_FOR_CAPTURE,
            ], true);
    }

    private function payload(BillingInvoice $invoice, ?BillingPayment $payment): array
    {
        $status = $this->status($invoice, $payment);
        $plan = BillingPlan::query()
            ->where('code', $invoice->plan_code)
            ->first();

        return [
            'status' => $status,
            'title' => $this->title($status),
            'message' => $this->message($status),
            'invoice' => [
                'id' => $invoice->id,
                'amount' => $invoice->amount_minor,
                'currency' => $invoice->currency,
                'plan_code' => $invoice->plan_code,
                'plan_name' => $plan?->name ?? $invoice->plan_code,
                'created_at' => $invoice->created_at?->toDateTimeString(),
                'paid_at' => $invoice->paid_at?->toDateTimeString(),
            ],
            'subscription' => $this->subscriptionPayload($invoice->subscription),
        ];
    }

    private function status(BillingInvoice $invoice, ?BillingPayment $payment): string
    {
        if ($invoice->status === BillingInvoice::STATUS_PAID || $payment?->status === BillingPayment::STATUS_SUCCEEDED) {
            return 'paid';
        }

        if (in_array($invoice->status, [BillingInvoice::STATUS_CANCELED, BillingInvoice::STATUS_EXPIRED], true)
            || $payment?->status === BillingPayment::STATUS_CANCELED) {
            return 'canceled';
        }

        if ($invoice->status === BillingInvoice::STATUS_FAILED
            || in_array($payment?->status, [BillingPayment::STATUS_FAILED, BillingPayment::STATUS_REFUNDED], true)) {
            return 'failed';
        }

        return 'pending';
    }

    private function subscriptionPayload(?BillingSubscription $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        return [
            'is_active' => in_array($subscription->status, ['active', 'trialing'], true),
            'plan_code' => $subscription->plan_code,
            'period_ends_at' => $subscription->current_period_end?->toDateTimeString(),
        ];
    }

    private function notFoundPayload(): array
    {
        return [
            'status' => 'not_found',
            'title' => 'Платёж не найден',
            'message' => 'Не удалось найти платёж по этой ссылке.',
            'invoice' => null,
            'subscription' => null,
        ];
    }

    private function title(string $status): string
    {
        return match ($status) {
            'paid' => 'Оплата прошла успешно',
            'canceled' => 'Платёж отменён',
            'failed' => 'Оплата не завершена',
            default => 'Платёж ещё обрабатывается',
        };
    }

    private function message(string $status): string
    {
        return match ($status) {
            'paid' => 'Тариф активирован. Спасибо за оплату.',
            'canceled' => 'Платёж был отменён. Вы можете вернуться к тарифам и попробовать снова.',
            'failed' => 'Проверка оплаты завершилась ошибкой. Попробуйте повторить оплату позже.',
            default => 'Обычно подтверждение занимает несколько секунд. Можно обновить статус вручную.',
        };
    }
}
