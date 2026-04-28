<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\BillingGateEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingProviderEvent;
use App\Models\FeatureEntitlement;
use App\Models\Project;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingAdminQueryService
{
    public function overview(Request $request): array
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($request);

        $eventQuery = $this->eventsInPeriod($periodStart, $periodEnd);

        return [
            'period' => $this->periodPayload($periodStart, $periodEnd),
            'totals' => [
                'users' => User::query()->count(),
                'active_projects' => Project::query()->whereNull('archived_at')->count(),
                'usage_events' => (clone $eventQuery)->count(),
                'storage_bytes_uploaded' => $this->sumMetric($eventQuery, BillingCodes::METRIC_STORAGE_BYTES_UPLOADED),
                'pdf_smeta_generated' => $this->sumMetric($eventQuery, BillingCodes::METRIC_PDF_SMETA_GENERATED),
                'pdf_price_justification_generated' => $this->sumMetric($eventQuery, BillingCodes::METRIC_PDF_PRICE_JUSTIFICATION_GENERATED),
                'pdf_evidence_run_generated' => $this->sumMetric($eventQuery, BillingCodes::METRIC_PDF_EVIDENCE_RUN_GENERATED),
                'evidence_runs_created' => $this->sumMetric($eventQuery, BillingCodes::METRIC_EVIDENCE_RUNS_CREATED),
                'chrome_extract_with_evidence' => $this->sumMetric($eventQuery, BillingCodes::METRIC_CHROME_EXTRACT_WITH_EVIDENCE),
            ],
            'top_metrics' => $this->topMetrics($eventQuery),
            'recent_events' => $this->recentEvents($eventQuery, 20),
            'storage' => [
                'bytes_uploaded_from_usage_events' => $this->sumMetric($eventQuery, BillingCodes::METRIC_STORAGE_BYTES_UPLOADED),
                'legacy_storage_not_included' => true,
            ],
            'billing_diagnostics' => $this->billingDiagnostics(),
        ];
    }

    public function userOverview(User $user, Request $request): array
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod($request);

        $eventQuery = $this->eventsInPeriod($periodStart, $periodEnd)
            ->where('user_id', $user->id);

        $subscription = BillingSubscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return [
            'period' => $this->periodPayload($periodStart, $periodEnd),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'billing' => $this->billingPayload($subscription),
            'entitlements' => FeatureEntitlement::query()
                ->where('owner_type', 'user')
                ->where('owner_id', $user->id)
                ->orderBy('feature_code')
                ->get(['feature_code', 'enabled', 'source', 'starts_at', 'ends_at'])
                ->map(fn (FeatureEntitlement $entitlement) => [
                    'feature_code' => $entitlement->feature_code,
                    'enabled' => (bool) $entitlement->enabled,
                    'source' => $entitlement->source,
                    'starts_at' => $entitlement->starts_at?->toDateTimeString(),
                    'ends_at' => $entitlement->ends_at?->toDateTimeString(),
                ])
                ->values(),
            'projects' => [
                'total' => Project::query()->where('user_id', $user->id)->count(),
                'active' => Project::query()->where('user_id', $user->id)->whereNull('archived_at')->count(),
                'archived' => Project::query()->where('user_id', $user->id)->whereNotNull('archived_at')->count(),
            ],
            'usage' => [
                'current_month' => UsageCounter::query()
                    ->where('owner_type', 'user')
                    ->where('owner_id', $user->id)
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->orderByDesc('quantity')
                    ->get(['metric_code', 'quantity', 'period_start', 'period_end'])
                    ->map(fn (UsageCounter $counter) => $this->counterPayload($counter))
                    ->values(),
            ],
            'storage' => [
                'bytes_uploaded' => $this->sumMetric($eventQuery, BillingCodes::METRIC_STORAGE_BYTES_UPLOADED),
                'bytes_uploaded_from_usage_events' => $this->sumMetric($eventQuery, BillingCodes::METRIC_STORAGE_BYTES_UPLOADED),
                'legacy_storage_not_included' => true,
            ],
            'recent_events' => $this->recentEvents($eventQuery, 20),
        ];
    }

    public function usage(Request $request): array
    {
        $limit = $this->resolveLimit($request, 100, 500);

        $query = UsageCounter::query()
            ->when($request->filled('user_id'), fn (Builder $query) => $query
                ->where('owner_type', 'user')
                ->where('owner_id', (int) $request->query('user_id')))
            ->when($request->filled('metric_code'), fn (Builder $query) => $query->where('metric_code', $request->query('metric_code')))
            ->when($request->filled('period_start'), fn (Builder $query) => $query->where('period_start', '>=', Carbon::parse($request->query('period_start'))->startOfDay()))
            ->when($request->filled('period_end'), fn (Builder $query) => $query->where('period_end', '<=', Carbon::parse($request->query('period_end'))->endOfDay()));

        if ($request->filled('feature_code') || $request->filled('project_id')) {
            return $this->usageFromEvents($request, $limit);
        }

        return [
            'filters' => $request->only(['user_id', 'metric_code', 'feature_code', 'project_id', 'period_start', 'period_end']),
            'items' => $query
                ->orderByDesc('period_start')
                ->orderByDesc('quantity')
                ->limit($limit)
                ->get()
                ->map(fn (UsageCounter $counter) => $this->counterPayload($counter))
                ->values(),
        ];
    }

    public function events(Request $request): array
    {
        $limit = $this->resolveLimit($request, 100, 500);

        $query = UsageEvent::query()
            ->with(['user:id,name,email', 'project:id,number'])
            ->when($request->filled('user_id'), fn (Builder $query) => $query->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('metric_code'), fn (Builder $query) => $query->where('metric_code', $request->query('metric_code')))
            ->when($request->filled('feature_code'), fn (Builder $query) => $query->where('feature_code', $request->query('feature_code')))
            ->when($request->filled('project_id'), fn (Builder $query) => $query->where('project_id', (int) $request->query('project_id')))
            ->when($request->filled('source'), fn (Builder $query) => $query->where('source', $request->query('source')))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('occurred_at', '>=', Carbon::parse($request->query('from'))))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('occurred_at', '<=', Carbon::parse($request->query('to'))));

        return [
            'filters' => $request->only(['user_id', 'metric_code', 'feature_code', 'project_id', 'source', 'from', 'to']),
            'limit' => $limit,
            'items' => $query
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (UsageEvent $event) => $this->eventPayload($event))
                ->values(),
        ];
    }

    private function usageFromEvents(Request $request, int $limit): array
    {
        $query = UsageEvent::query()
            ->when($request->filled('user_id'), fn (Builder $query) => $query->where('user_id', (int) $request->query('user_id')))
            ->when($request->filled('metric_code'), fn (Builder $query) => $query->where('metric_code', $request->query('metric_code')))
            ->when($request->filled('feature_code'), fn (Builder $query) => $query->where('feature_code', $request->query('feature_code')))
            ->when($request->filled('project_id'), fn (Builder $query) => $query->where('project_id', (int) $request->query('project_id')))
            ->when($request->filled('period_start'), fn (Builder $query) => $query->where('occurred_at', '>=', Carbon::parse($request->query('period_start'))->startOfDay()))
            ->when($request->filled('period_end'), fn (Builder $query) => $query->where('occurred_at', '<=', Carbon::parse($request->query('period_end'))->endOfDay()));

        return [
            'filters' => $request->only(['user_id', 'metric_code', 'feature_code', 'project_id', 'period_start', 'period_end']),
            'items' => $query
                ->select([
                    'owner_type',
                    'owner_id',
                    'metric_code',
                    DB::raw('SUM(quantity) as quantity'),
                    DB::raw('MIN(occurred_at) as period_start'),
                    DB::raw('MAX(occurred_at) as period_end'),
                ])
                ->groupBy('owner_type', 'owner_id', 'metric_code')
                ->orderByDesc('quantity')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'owner_type' => $row->owner_type,
                    'owner_id' => (int) $row->owner_id,
                    'metric_code' => $row->metric_code,
                    'quantity' => (float) $row->quantity,
                    'period_start' => Carbon::parse($row->period_start)->toDateString(),
                    'period_end' => Carbon::parse($row->period_end)->toDateString(),
                ])
                ->values(),
        ];
    }

    private function resolvePeriod(Request $request): array
    {
        $start = $request->filled('period_start')
            ? Carbon::parse($request->query('period_start'))->startOfDay()
            : now()->startOfMonth();

        $end = $request->filled('period_end')
            ? Carbon::parse($request->query('period_end'))->endOfDay()
            : now()->endOfMonth();

        return [$start, $end];
    }

    private function eventsInPeriod(CarbonInterface $periodStart, CarbonInterface $periodEnd): Builder
    {
        return UsageEvent::query()
            ->whereBetween('occurred_at', [$periodStart, $periodEnd]);
    }

    private function sumMetric(Builder $query, string $metricCode): float
    {
        return (float) (clone $query)
            ->where('metric_code', $metricCode)
            ->sum('quantity');
    }

    private function topMetrics(Builder $query): array
    {
        return (clone $query)
            ->select('metric_code', DB::raw('SUM(quantity) as quantity'))
            ->groupBy('metric_code')
            ->orderByDesc('quantity')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'metric_code' => $row->metric_code,
                'quantity' => (float) $row->quantity,
            ])
            ->values()
            ->all();
    }

    private function recentEvents(Builder $query, int $limit): array
    {
        return (clone $query)
            ->with(['user:id,name,email', 'project:id,number'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (UsageEvent $event) => $this->eventPayload($event))
            ->values()
            ->all();
    }

    private function eventPayload(UsageEvent $event): array
    {
        return [
            'id' => $event->id,
            'occurred_at' => $event->occurred_at?->toDateTimeString(),
            'owner_type' => $event->owner_type,
            'owner_id' => (int) $event->owner_id,
            'user' => $event->user ? [
                'id' => $event->user->id,
                'name' => $event->user->name,
                'email' => $event->user->email,
            ] : null,
            'project' => $event->project ? [
                'id' => $event->project->id,
                'number' => $event->project->number,
            ] : null,
            'metric_code' => $event->metric_code,
            'feature_code' => $event->feature_code,
            'quantity' => (float) $event->quantity,
            'unit' => $event->unit,
            'source' => $event->source,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
        ];
    }

    private function counterPayload(UsageCounter $counter): array
    {
        return [
            'owner_type' => $counter->owner_type,
            'owner_id' => (int) $counter->owner_id,
            'metric_code' => $counter->metric_code,
            'quantity' => (float) $counter->quantity,
            'period_start' => $counter->period_start?->toDateString(),
            'period_end' => $counter->period_end?->toDateString(),
        ];
    }

    private function billingPayload(?BillingSubscription $subscription): array
    {
        if (! $subscription) {
            return [
                'plan_code' => config('billing.default_plan', 'legacy_unlimited'),
                'subscription_status' => 'fallback',
                'source' => 'fallback',
            ];
        }

        return [
            'plan_code' => $subscription->plan_code ?: $subscription->plan?->code ?: config('billing.default_plan', 'legacy_unlimited'),
            'subscription_status' => $subscription->status,
            'source' => $subscription->source,
        ];
    }

    private function billingDiagnostics(): array
    {
        return [
            'enabled' => (bool) config('billing.enabled', false),
            'enforce_limits' => (bool) config('billing.enforce_limits', false),
            'checkout_ui_enabled' => (bool) config('billing.payments.checkout_ui_enabled', false),
            'default_provider' => config('billing.payments.default_provider'),
            'yookassa_enabled' => (bool) config('billing.payments.providers.yookassa.enabled', false),
            'yookassa_mode' => config('billing.payments.providers.yookassa.mode'),
            'plans_count' => BillingPlan::query()->count(),
            'active_subscriptions_count' => BillingSubscription::query()->where('status', 'active')->count(),
            'invoices_count' => BillingInvoice::query()->count(),
            'payments_count' => BillingPayment::query()->count(),
            'webhook_events_count' => BillingProviderEvent::query()->count(),
            'would_block_events_count' => BillingGateEvent::query()->where('would_block', true)->count(),
        ];
    }

    private function periodPayload(CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        return [
            'start' => $periodStart->toDateString(),
            'end' => $periodEnd->toDateString(),
        ];
    }

    private function resolveLimit(Request $request, int $default, int $max): int
    {
        $limit = (int) $request->query('limit', $default);

        return max(1, min($limit, $max));
    }
}
