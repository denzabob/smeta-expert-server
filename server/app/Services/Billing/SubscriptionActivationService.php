<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionActivationService
{
    public function activateFromPaidInvoice(BillingInvoice $invoice): BillingSubscription
    {
        return DB::transaction(function () use ($invoice) {
            /** @var BillingInvoice $lockedInvoice */
            $lockedInvoice = BillingInvoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->subscription_id) {
                return BillingSubscription::query()->findOrFail($lockedInvoice->subscription_id);
            }

            $plan = BillingPlan::query()
                ->where('code', $lockedInvoice->plan_code)
                ->first();

            if (! $plan) {
                throw new RuntimeException("Billing plan [{$lockedInvoice->plan_code}] was not found.");
            }

            $subscription = BillingSubscription::query()
                ->where('user_id', $lockedInvoice->user_id)
                ->whereIn('status', ['active', 'trialing'])
                ->orderByDesc('current_period_end')
                ->lockForUpdate()
                ->first();

            $isUpgrade = ($lockedInvoice->metadata_json['checkout_type'] ?? null) === 'upgrade';
            $previousSubscription = $isUpgrade ? $subscription : null;
            $periodStart = $isUpgrade ? now() : $this->resolvePeriodStart($subscription);
            $periodEnd = $this->resolvePeriodEnd($periodStart, $lockedInvoice->metadata_json['billing_period'] ?? 'month');

            if ($isUpgrade && $subscription) {
                $subscription->forceFill([
                    'status' => 'replaced',
                    'current_period_end' => now(),
                ])->save();

                $subscription = null;
            }

            if (! $subscription) {
                $subscription = new BillingSubscription([
                    'user_id' => $lockedInvoice->user_id,
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'status' => 'active',
                    'source' => 'payment',
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                    'overrides_json' => $this->subscriptionOverrides($lockedInvoice, $previousSubscription),
                ]);
            } else {
                $subscription->fill([
                    'plan_id' => $plan->id,
                    'plan_code' => $plan->code,
                    'status' => 'active',
                    'source' => 'payment',
                    'current_period_end' => $periodEnd,
                    'overrides_json' => $this->subscriptionOverrides($lockedInvoice, null, $subscription->overrides_json ?? []),
                ]);
            }

            $subscription->save();

            $lockedInvoice->forceFill([
                'subscription_id' => $subscription->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ])->save();

            return $subscription;
        });
    }

    private function resolvePeriodStart(?BillingSubscription $subscription): Carbon
    {
        if ($subscription?->current_period_end && $subscription->current_period_end->isFuture()) {
            return $subscription->current_period_end->copy();
        }

        return now();
    }

    private function resolvePeriodEnd(Carbon $periodStart, string $billingPeriod): Carbon
    {
        return match ($billingPeriod) {
            'year' => $periodStart->copy()->addYear(),
            default => $periodStart->copy()->addMonth(),
        };
    }

    private function subscriptionOverrides(
        BillingInvoice $invoice,
        ?BillingSubscription $previousSubscription,
        array $existing = [],
    ): array {
        $metadata = $invoice->metadata_json ?? [];
        $overrides = array_merge($existing, [
            'source' => ($metadata['checkout_type'] ?? null) === 'upgrade' ? 'checkout_upgrade' : ($metadata['source'] ?? 'payment'),
        ]);

        if ($previousSubscription) {
            $overrides['previous_subscription_id'] = $previousSubscription->id;
            $overrides['previous_plan_code'] = $previousSubscription->plan_code;
        }

        return $overrides;
    }
}
