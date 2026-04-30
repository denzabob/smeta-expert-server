<?php

namespace App\Services\Billing\Payments;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingProviderEvent;
use App\Models\User;
use App\Services\Billing\Payments\DTO\PaymentIntentOptions;
use App\Services\Billing\Payments\DTO\ProviderPaymentResult;
use App\Services\Billing\SubscriptionActivationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BillingPaymentService
{
    public function __construct(
        private PaymentProviderManager $providerManager,
        private SubscriptionActivationService $subscriptionActivationService,
        private ProviderPayloadSanitizer $payloadSanitizer,
    ) {}

    public function createInvoiceForPlan(User $user, string $planCode, array $options = []): BillingInvoice
    {
        $plan = BillingPlan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            throw ValidationException::withMessages([
                'plan_code' => 'Billing plan was not found.',
            ]);
        }

        $metadata = $plan->metadata_json ?? [];
        $priceMinor = $metadata['price_minor'] ?? null;
        $currency = strtoupper((string) ($metadata['currency'] ?? 'RUB'));

        if (! is_numeric($priceMinor) || (int) $priceMinor <= 0) {
            throw ValidationException::withMessages([
                'plan_code' => 'Plan does not have price configured.',
            ]);
        }

        $billingPeriod = $options['billing_period'] ?? $metadata['billing_period'] ?? 'month';

        $invoiceMetadata = array_merge([
            'billing_period' => $billingPeriod,
            'created_by_admin_id' => $options['created_by_admin_id'] ?? null,
            'source' => $options['source'] ?? 'admin_test',
        ], $options['metadata'] ?? []);

        return BillingInvoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'amount_minor' => (int) $priceMinor,
            'currency' => $currency,
            'status' => BillingInvoice::STATUS_DRAFT,
            'description' => $options['description'] ?? "PrismCore: тариф {$plan->name}",
            'metadata_json' => $invoiceMetadata,
        ]);
    }

    public function createPaymentForInvoice(BillingInvoice $invoice, ?string $providerCode = null, array $options = []): BillingPayment
    {
        $provider = $this->providerManager->driver($providerCode);
        $this->providerManager->assertEnabled($provider->code());

        if ($invoice->status === BillingInvoice::STATUS_PAID) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice is already paid.',
            ]);
        }

        $this->ensurePaymentCanBeCreated($invoice, $provider->code());

        $idempotencyKey = (string) Str::uuid();

        /** @var BillingPayment $payment */
        $payment = BillingPayment::query()->create([
            'uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider_code' => $provider->code(),
            'idempotency_key' => $idempotencyKey,
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
            'status' => BillingPayment::STATUS_CREATED,
        ]);

        try {
            $result = $provider->createPaymentIntent($invoice, new PaymentIntentOptions(
                confirmationType: 'redirect',
                returnUrl: $options['return_url'] ?? config('billing.payments.providers.' . $provider->code() . '.return_url'),
                description: $invoice->description,
                metadata: [
                    'invoice_uuid' => $invoice->uuid,
                    'user_id' => (string) $invoice->user_id,
                    'payment_uuid' => $payment->uuid,
                ],
                idempotencyKey: $idempotencyKey,
            ));

            $payment->forceFill([
                'provider_payment_id' => $result->providerPaymentId,
                'status' => $this->normalizeProviderStatus($result->status),
                'confirmation_type' => $result->confirmationType,
                'confirmation_url' => $result->confirmationUrl,
                'confirmation_token' => $result->confirmationToken,
                'provider_payload' => $this->payloadSanitizer->sanitize($result->rawPayload),
            ])->save();

            $invoice->forceFill([
                'status' => BillingInvoice::STATUS_PENDING_PAYMENT,
            ])->save();

            return $payment->refresh();
        } catch (Throwable $e) {
            $payment->forceFill([
                'status' => BillingPayment::STATUS_FAILED,
                'error_code' => 'provider_error',
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    public function refreshProviderPaymentStatus(BillingPayment $payment): BillingPayment
    {
        if (! $payment->provider_payment_id) {
            throw new RuntimeException('Payment does not have provider payment id.');
        }

        $provider = $this->providerManager->driver($payment->provider_code);
        $providerPayment = $provider->getPayment($payment->provider_payment_id);
        $this->assertProviderPaymentMatches($payment, $providerPayment);

        DB::transaction(function () use ($payment, $providerPayment) {
            $lockedPayment = BillingPayment::query()
                ->with('invoice')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            match ($providerPayment->status) {
                'succeeded' => $this->markPaymentSucceeded($lockedPayment, $providerPayment),
                'canceled' => $this->markPaymentCanceled($lockedPayment, $providerPayment),
                'waiting_for_capture' => $this->markPaymentWaitingForCapture($lockedPayment, $providerPayment),
                default => $this->markPaymentPending($lockedPayment, $providerPayment),
            };
        });

        return $payment->refresh()->load(['invoice.subscription']);
    }

    public function handleProviderWebhook(string $providerCode, array $payload, array $headers = []): BillingProviderEvent
    {
        $provider = $this->providerManager->driver($providerCode);

        /** @var BillingProviderEvent $event */
        $event = BillingProviderEvent::query()->create([
            'provider_code' => $provider->code(),
            'event_type' => (string) ($payload['event'] ?? 'unknown'),
            'provider_object_id' => data_get($payload, 'object.id'),
            'provider_payment_id' => data_get($payload, 'object.id'),
            'payload' => $payload,
            'headers' => $this->safeHeaders($headers),
            'processing_status' => BillingProviderEvent::STATUS_RECEIVED,
        ]);

        try {
            $parsedEvent = $provider->parseWebhook($payload, $headers);
            $event->forceFill([
                'event_type' => $parsedEvent->eventType,
                'provider_object_id' => $parsedEvent->providerObjectId,
                'provider_payment_id' => $parsedEvent->providerPaymentId,
                'payload' => $parsedEvent->payload,
            ])->save();

            if (! $parsedEvent->providerPaymentId || ! str_starts_with($parsedEvent->eventType, 'payment.')) {
                return $this->markEvent($event, BillingProviderEvent::STATUS_IGNORED);
            }

            $payment = BillingPayment::query()
                ->with('invoice')
                ->where('provider_code', $provider->code())
                ->where('provider_payment_id', $parsedEvent->providerPaymentId)
                ->first();

            if (! $payment) {
                return $this->markEvent($event, BillingProviderEvent::STATUS_IGNORED);
            }

            $providerPayment = $provider->getPayment($parsedEvent->providerPaymentId);
            $this->assertProviderPaymentMatches($payment, $providerPayment);

            DB::transaction(function () use ($payment, $providerPayment) {
                $lockedPayment = BillingPayment::query()
                    ->with('invoice')
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                match ($providerPayment->status) {
                    'succeeded' => $this->markPaymentSucceeded($lockedPayment, $providerPayment),
                    'canceled' => $this->markPaymentCanceled($lockedPayment, $providerPayment),
                    'waiting_for_capture' => $this->markPaymentWaitingForCapture($lockedPayment, $providerPayment),
                    default => $this->markPaymentPending($lockedPayment, $providerPayment),
                };
            });

            return $this->markEvent($event, BillingProviderEvent::STATUS_PROCESSED);
        } catch (Throwable $e) {
            report($e);

            $event->forceFill([
                'processing_status' => BillingProviderEvent::STATUS_FAILED,
                'processing_error' => $e->getMessage(),
                'processed_at' => now(),
            ])->save();

            throw $e;
        }
    }

    public function markPaymentSucceeded(BillingPayment $payment, ProviderPaymentResult $result): void
    {
        if ($payment->status !== BillingPayment::STATUS_SUCCEEDED) {
            $payment->forceFill([
                'status' => BillingPayment::STATUS_SUCCEEDED,
                'provider_payload' => $this->payloadSanitizer->sanitize($result->rawPayload),
                'succeeded_at' => $payment->succeeded_at ?: now(),
            ])->save();
        }

        $invoice = $payment->invoice;

        if ($invoice->status !== BillingInvoice::STATUS_PAID) {
            $invoice->forceFill([
                'status' => BillingInvoice::STATUS_PAID,
                'paid_at' => $invoice->paid_at ?: now(),
                'canceled_at' => null,
            ])->save();
        }

        $this->subscriptionActivationService->activateFromPaidInvoice($invoice);
    }

    public function markPaymentCanceled(BillingPayment $payment, ProviderPaymentResult $result): void
    {
        $payment->forceFill([
            'status' => BillingPayment::STATUS_CANCELED,
            'provider_payload' => $this->payloadSanitizer->sanitize($result->rawPayload),
            'canceled_at' => $payment->canceled_at ?: now(),
        ])->save();

        if ($payment->invoice->status !== BillingInvoice::STATUS_PAID) {
            $payment->invoice->forceFill([
                'status' => BillingInvoice::STATUS_CANCELED,
                'canceled_at' => $payment->invoice->canceled_at ?: now(),
            ])->save();
        }
    }

    private function markPaymentWaitingForCapture(BillingPayment $payment, ProviderPaymentResult $result): void
    {
        $payment->forceFill([
            'status' => BillingPayment::STATUS_WAITING_FOR_CAPTURE,
            'provider_payload' => $this->payloadSanitizer->sanitize($result->rawPayload),
        ])->save();
    }

    private function markPaymentPending(BillingPayment $payment, ProviderPaymentResult $result): void
    {
        $payment->forceFill([
            'status' => $this->normalizeProviderStatus($result->status),
            'provider_payload' => $this->payloadSanitizer->sanitize($result->rawPayload),
        ])->save();
    }

    private function assertProviderPaymentMatches(BillingPayment $payment, ProviderPaymentResult $result): void
    {
        if ($payment->amount_minor !== $result->amountMinor || strtoupper($payment->currency) !== strtoupper($result->currency)) {
            throw new RuntimeException('Provider payment amount or currency does not match local payment.');
        }
    }

    private function normalizeProviderStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => BillingPayment::STATUS_SUCCEEDED,
            'canceled' => BillingPayment::STATUS_CANCELED,
            'failed' => BillingPayment::STATUS_FAILED,
            'refunded' => BillingPayment::STATUS_REFUNDED,
            'waiting_for_capture' => BillingPayment::STATUS_WAITING_FOR_CAPTURE,
            'pending' => BillingPayment::STATUS_PENDING,
            default => BillingPayment::STATUS_PENDING,
        };
    }

    private function markEvent(BillingProviderEvent $event, string $status): BillingProviderEvent
    {
        $event->forceFill([
            'processing_status' => $status,
            'processed_at' => now(),
        ])->save();

        return $event->refresh();
    }

    private function ensurePaymentCanBeCreated(BillingInvoice $invoice, string $providerCode): void
    {
        if ($providerCode !== 'yookassa' || ! config('billing.payments.providers.yookassa.receipts_enabled')) {
            return;
        }

        $invoice->loadMissing('user');

        if (! $invoice->user?->email) {
            throw ValidationException::withMessages([
                'payer_email' => 'Для создания платежа YooKassa с чеком нужен email плательщика',
            ]);
        }
    }

    private function safeHeaders(array $headers): array
    {
        return $this->payloadSanitizer->sanitize($headers);
    }
}
