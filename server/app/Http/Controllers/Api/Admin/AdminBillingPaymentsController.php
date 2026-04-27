<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingProviderEvent;
use App\Models\User;
use App\Services\Billing\Payments\BillingPaymentService;
use App\Services\Billing\Payments\ProviderPayloadSanitizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdminBillingPaymentsController extends Controller
{
    public function __construct(
        private BillingPaymentService $paymentService,
        private ProviderPayloadSanitizer $payloadSanitizer,
    ) {}

    public function invoices(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:40',
            'plan_code' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = BillingInvoice::query()
            ->with('user:id,name,email')
            ->when(isset($validated['user_id']), fn (Builder $query) => $query->where('user_id', (int) $validated['user_id']))
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->when(isset($validated['plan_code']), fn (Builder $query) => $query->where('plan_code', $validated['plan_code']))
            ->when(isset($validated['from']), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()))
            ->when(isset($validated['to']), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json($this->paginatedPayload(
            $paginator,
            fn (BillingInvoice $invoice) => $this->invoicePayload($invoice),
        ));
    }

    public function payments(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'invoice_id' => 'nullable|integer|min:1',
            'user_id' => 'nullable|integer|min:1',
            'status' => 'nullable|string|max:40',
            'provider_code' => 'nullable|string|max:40',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = BillingPayment::query()
            ->with('user:id,name,email')
            ->when(isset($validated['invoice_id']), fn (Builder $query) => $query->where('invoice_id', (int) $validated['invoice_id']))
            ->when(isset($validated['user_id']), fn (Builder $query) => $query->where('user_id', (int) $validated['user_id']))
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->when(isset($validated['provider_code']), fn (Builder $query) => $query->where('provider_code', $validated['provider_code']))
            ->when(isset($validated['from']), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()))
            ->when(isset($validated['to']), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json($this->paginatedPayload(
            $paginator,
            fn (BillingPayment $payment) => $this->paymentPayload($payment),
        ));
    }

    public function providerEvents(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'provider_code' => 'nullable|string|max:40',
            'event_type' => 'nullable|string|max:100',
            'processing_status' => 'nullable|string|max:40',
            'provider_payment_id' => 'nullable|string|max:120',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $paginator = BillingProviderEvent::query()
            ->when(isset($validated['provider_code']), fn (Builder $query) => $query->where('provider_code', $validated['provider_code']))
            ->when(isset($validated['event_type']), fn (Builder $query) => $query->where('event_type', $validated['event_type']))
            ->when(isset($validated['processing_status']), fn (Builder $query) => $query->where('processing_status', $validated['processing_status']))
            ->when(isset($validated['provider_payment_id']), fn (Builder $query) => $query->where('provider_payment_id', $validated['provider_payment_id']))
            ->when(isset($validated['from']), fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay()))
            ->when(isset($validated['to']), fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json($this->paginatedPayload(
            $paginator,
            fn (BillingProviderEvent $event) => $this->providerEventPayload($event),
        ));
    }

    public function paymentPlans(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $plans = BillingPlan::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->filter(fn (BillingPlan $plan) => is_numeric($plan->metadata_json['price_minor'] ?? null))
            ->map(fn (BillingPlan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'price_minor' => (int) $plan->metadata_json['price_minor'],
                'currency' => strtoupper((string) ($plan->metadata_json['currency'] ?? 'RUB')),
                'billing_period' => $plan->metadata_json['billing_period'] ?? 'month',
            ])
            ->values();

        return response()->json([
            'data' => $plans,
        ]);
    }

    public function storeInvoice(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'plan_code' => 'required|string|max:100',
            'billing_period' => 'nullable|in:month,year',
            'description' => 'nullable|string|max:500',
        ]);

        $invoice = $this->paymentService->createInvoiceForPlan(
            User::query()->findOrFail((int) $validated['user_id']),
            $validated['plan_code'],
            [
                'billing_period' => $validated['billing_period'] ?? 'month',
                'description' => $validated['description'] ?? null,
                'created_by_admin_id' => $request->user()->id,
                'source' => 'admin_test',
            ],
        );

        return response()->json([
            'invoice' => $this->invoicePayload($invoice),
        ], 201);
    }

    public function storePayment(Request $request, BillingInvoice $invoice): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'provider_code' => 'nullable|string|max:40',
        ]);

        try {
            $payment = $this->paymentService->createPaymentForInvoice(
                $invoice,
                $validated['provider_code'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'payment' => $this->paymentPayload($payment),
        ], 201);
    }

    public function showInvoice(Request $request, BillingInvoice $invoice): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'invoice' => $this->invoicePayload($invoice->load('payments')),
        ]);
    }

    public function showPayment(Request $request, BillingPayment $payment): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'payment' => $this->paymentPayload($payment->load('invoice')),
        ]);
    }

    public function invoiceDetails(Request $request, BillingInvoice $invoice): JsonResponse
    {
        $this->authorizeAdmin($request);

        $invoice->load(['user:id,name,email', 'payments', 'subscription']);

        return response()->json([
            'invoice' => $this->invoiceDetailsPayload($invoice),
            'payments' => $invoice->payments->map(fn (BillingPayment $payment) => $this->paymentPayload($payment))->values(),
            'subscription' => $invoice->subscription ? $this->subscriptionPayload($invoice->subscription) : null,
        ]);
    }

    public function paymentDetails(Request $request, BillingPayment $payment): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payment->load(['invoice']);

        $providerEvents = BillingProviderEvent::query()
            ->where('provider_code', $payment->provider_code)
            ->where('provider_payment_id', $payment->provider_payment_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json([
            'payment' => $this->paymentDetailsPayload($payment),
            'invoice' => $payment->invoice ? $this->invoiceSummaryPayload($payment->invoice) : null,
            'provider_events' => $providerEvents
                ->map(fn (BillingProviderEvent $event) => $this->providerEventPayload($event))
                ->values(),
        ]);
    }

    public function providerEventDetails(Request $request, BillingProviderEvent $event): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payment = $event->provider_payment_id
            ? BillingPayment::query()
                ->where('provider_code', $event->provider_code)
                ->where('provider_payment_id', $event->provider_payment_id)
                ->first()
            : null;

        return response()->json([
            'event' => $this->providerEventDetailsPayload($event),
            'payment' => $payment ? [
                'id' => $payment->id,
                'status' => $payment->status,
                'invoice_id' => $payment->invoice_id,
            ] : null,
        ]);
    }

    public function refreshProviderStatus(Request $request, BillingPayment $payment): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $payment = $this->paymentService->refreshProviderPaymentStatus($payment);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $invoice = $payment->invoice;
        $subscription = $invoice?->subscription;

        return response()->json([
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'provider_payment_id' => $payment->provider_payment_id,
                'updated_at' => $payment->updated_at?->toDateTimeString(),
            ],
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'status' => $invoice->status,
            ] : null,
            'subscription' => $subscription ? $this->subscriptionPayload($subscription) : null,
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }

    private function invoicePayload(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'uuid' => $invoice->uuid,
            'user_id' => $invoice->user_id,
            'user' => $invoice->relationLoaded('user') && $invoice->user ? [
                'id' => $invoice->user->id,
                'name' => $invoice->user->name,
                'email' => $invoice->user->email,
            ] : null,
            'subscription_id' => $invoice->subscription_id,
            'plan_code' => $invoice->plan_code,
            'period_start' => $invoice->period_start?->toDateTimeString(),
            'period_end' => $invoice->period_end?->toDateTimeString(),
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
            'status' => $invoice->status,
            'description' => $invoice->description,
            'metadata_json' => $invoice->metadata_json,
            'paid_at' => $invoice->paid_at?->toDateTimeString(),
            'canceled_at' => $invoice->canceled_at?->toDateTimeString(),
            'created_at' => $invoice->created_at?->toDateTimeString(),
            'updated_at' => $invoice->updated_at?->toDateTimeString(),
            'payments' => $invoice->relationLoaded('payments')
                ? $invoice->payments->map(fn (BillingPayment $payment) => $this->paymentPayload($payment))->values()
                : null,
        ];
    }

    private function paymentPayload(BillingPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'uuid' => $payment->uuid,
            'invoice_id' => $payment->invoice_id,
            'user_id' => $payment->user_id,
            'user' => $payment->relationLoaded('user') && $payment->user ? [
                'id' => $payment->user->id,
                'name' => $payment->user->name,
                'email' => $payment->user->email,
            ] : null,
            'provider_code' => $payment->provider_code,
            'provider_payment_id' => $payment->provider_payment_id,
            'idempotency_key' => $payment->idempotency_key,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'confirmation_type' => $payment->confirmation_type,
            'confirmation_url' => $payment->confirmation_url,
            'confirmation_token' => $payment->confirmation_token,
            'error_code' => $payment->error_code,
            'error_message' => $payment->error_message,
            'succeeded_at' => $payment->succeeded_at?->toDateTimeString(),
            'canceled_at' => $payment->canceled_at?->toDateTimeString(),
            'created_at' => $payment->created_at?->toDateTimeString(),
            'updated_at' => $payment->updated_at?->toDateTimeString(),
        ];
    }

    private function providerEventPayload(BillingProviderEvent $event): array
    {
        return [
            'id' => $event->id,
            'provider_code' => $event->provider_code,
            'event_type' => $event->event_type,
            'provider_object_id' => $event->provider_object_id,
            'provider_payment_id' => $event->provider_payment_id,
            'processing_status' => $event->processing_status,
            'processing_error' => $event->processing_error,
            'processed_at' => $event->processed_at?->toDateTimeString(),
            'created_at' => $event->created_at?->toDateTimeString(),
        ];
    }

    private function invoiceDetailsPayload(BillingInvoice $invoice): array
    {
        return [
            ...$this->invoicePayload($invoice),
            'description' => $invoice->description,
            'metadata_json' => $this->payloadSanitizer->sanitize($invoice->metadata_json ?? []),
        ];
    }

    private function invoiceSummaryPayload(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'uuid' => $invoice->uuid,
            'status' => $invoice->status,
            'plan_code' => $invoice->plan_code,
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
        ];
    }

    private function paymentDetailsPayload(BillingPayment $payment): array
    {
        return [
            ...$this->paymentPayload($payment),
            'provider_payload' => $this->payloadSanitizer->sanitize($payment->provider_payload ?? []),
        ];
    }

    private function providerEventDetailsPayload(BillingProviderEvent $event): array
    {
        return [
            ...$this->providerEventPayload($event),
            'payload' => $this->payloadSanitizer->sanitize($event->payload ?? []),
            'headers' => $this->payloadSanitizer->sanitize($event->headers ?? []),
        ];
    }

    private function subscriptionPayload($subscription): array
    {
        return [
            'id' => $subscription->id,
            'plan_code' => $subscription->plan_code,
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start?->toDateTimeString(),
            'current_period_end' => $subscription->current_period_end?->toDateTimeString(),
        ];
    }

    private function perPage(Request $request): int
    {
        return max(1, min((int) $request->query('limit', 25), 100));
    }

    private function paginatedPayload($paginator, callable $mapper): array
    {
        return [
            'data' => $paginator->getCollection()->map($mapper)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
