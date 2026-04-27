<?php

namespace App\Services\Billing;

use App\Models\BillingGateEvent;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\DTO\BillingGateResult;
use Carbon\Carbon;
use Throwable;

class BillingGateService
{
    public function check(User $user, string $capability, array $context = []): BillingGateResult
    {
        $logOnly = (bool) config('billing.log_only', true);
        $enforced = (bool) config('billing.enforce_limits', false);

        try {
            $plan = $this->resolvePlan($user);
            $planCode = $plan?->code ?? (string) config('billing.default_plan', 'legacy_unlimited');
            $limit = $this->resolveLimit($plan, $capability);
            $usage = $this->resolveUsage($user, $capability, $context);
            $wouldBlock = $limit !== null && $usage >= $limit;

            $allowed = true;
            $reason = $wouldBlock ? 'limit_would_block' : 'allowed';

            if ((bool) config('billing.enabled', false) && ! $logOnly && $enforced && $wouldBlock) {
                $allowed = false;
                $reason = 'limit_enforced';
            }

            $result = new BillingGateResult(
                allowed: $allowed,
                logOnly: $logOnly,
                planCode: $planCode,
                capability: $capability,
                limit: $limit,
                usage: $usage,
                wouldBlock: $wouldBlock,
                enforced: ! $allowed && $enforced,
                reason: $reason,
            );

            if ($wouldBlock || (bool) ($context['force_log'] ?? false)) {
                $this->recordEvent($user, $result, $context);
            }

            return $result;
        } catch (Throwable $e) {
            report($e);

            if ((bool) config('billing.fail_open', true)) {
                return new BillingGateResult(
                    allowed: true,
                    logOnly: $logOnly,
                    planCode: (string) config('billing.default_plan', 'legacy_unlimited'),
                    capability: $capability,
                    limit: null,
                    usage: 0,
                    wouldBlock: false,
                    enforced: false,
                    reason: 'fail_open',
                );
            }

            return new BillingGateResult(
                allowed: false,
                logOnly: $logOnly,
                planCode: (string) config('billing.default_plan', 'legacy_unlimited'),
                capability: $capability,
                limit: null,
                usage: 0,
                wouldBlock: true,
                enforced: $enforced,
                reason: 'exception',
            );
        }
    }

    protected function resolvePlan(User $user): ?BillingPlan
    {
        $subscription = BillingSubscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->orderByDesc('current_period_end')
            ->orderByDesc('id')
            ->first();

        if ($subscription?->plan) {
            return $subscription->plan;
        }

        $planCode = $subscription?->plan_code ?: config('billing.default_plan', 'legacy_unlimited');

        return BillingPlan::query()
            ->where('code', $planCode)
            ->where('is_active', true)
            ->first();
    }

    protected function resolveLimit(?BillingPlan $plan, string $capability): ?int
    {
        $limits = $plan?->metadata_json['limits'] ?? [];

        if (! array_key_exists($capability, $limits) || $limits[$capability] === null) {
            return null;
        }

        return (int) $limits[$capability];
    }

    protected function resolveUsage(User $user, string $capability, array $context): int
    {
        if (array_key_exists('usage', $context)) {
            return (int) $context['usage'];
        }

        if ($capability === BillingCodes::CAP_PROJECTS_MAX_ACTIVE) {
            return Project::query()
                ->where('user_id', $user->id)
                ->whereNull('archived_at')
                ->count();
        }

        $metricCodes = $this->metricCodesForCapability($capability);

        if ($metricCodes === []) {
            return 0;
        }

        return (int) UsageEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('metric_code', $metricCodes)
            ->whereBetween('occurred_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('quantity');
    }

    protected function metricCodesForCapability(string $capability): array
    {
        return match ($capability) {
            BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => [
                BillingCodes::METRIC_PDF_SMETA_GENERATED,
                BillingCodes::METRIC_PDF_REVISION_GENERATED,
                BillingCodes::METRIC_PDF_PRICE_JUSTIFICATION_GENERATED,
            ],
            BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT => [
                BillingCodes::METRIC_EVIDENCE_RUNS_CREATED,
                BillingCodes::METRIC_PDF_EVIDENCE_RUN_GENERATED,
            ],
            BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT => [
                BillingCodes::METRIC_CHROME_EXTRACT_WITH_EVIDENCE,
                BillingCodes::METRIC_EVIDENCE_CHROME_CAPTURES,
                BillingCodes::METRIC_EVIDENCE_CHROME_ITEM_CAPTURES,
            ],
            default => [],
        };
    }

    protected function recordEvent(User $user, BillingGateResult $result, array $context): void
    {
        BillingGateEvent::query()->create([
            'user_id' => $user->id,
            'plan_code' => $result->planCode,
            'capability' => $result->capability,
            'limit_value' => $result->limit,
            'usage_value' => $result->usage,
            'would_block' => $result->wouldBlock,
            'enforced' => $result->enforced,
            'context_json' => $this->safeContext($context),
        ]);
    }

    private function safeContext(array $context): array
    {
        unset($context['user'], $context['project']);

        return $context;
    }
}
