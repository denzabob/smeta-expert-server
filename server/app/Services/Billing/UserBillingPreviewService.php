<?php

namespace App\Services\Billing;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\UsageEvent;
use App\Models\User;
use Carbon\Carbon;

class UserBillingPreviewService
{
    public function preview(User $user): array
    {
        $subscription = $this->activeSubscription($user);
        $plan = $this->resolvePlan($subscription);
        $planCode = $subscription?->plan_code ?: $plan?->code ?: (string) config('billing.default_plan', 'legacy_unlimited');

        if (! $plan) {
            $plan = $this->fallbackPlan($planCode);
        }

        return [
            'billing' => [
                'enabled' => (bool) config('billing.enabled', false),
                'enforce_limits' => (bool) config('billing.enforce_limits', false),
                'log_only' => (bool) config('billing.log_only', true),
                'checkout_enabled' => (bool) config('billing.payments.checkout_ui_enabled', false),
                'mode_label' => 'Тестовый период',
            ],
            'current_plan' => $this->currentPlanPayload($plan, $subscription === null),
            'subscription' => $this->subscriptionPayload($subscription),
            'usage' => $this->usagePayload($user, $plan),
            'public_plans' => $this->publicPlansPayload($plan->code),
        ];
    }

    private function activeSubscription(User $user): ?BillingSubscription
    {
        return BillingSubscription::query()
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
    }

    private function resolvePlan(?BillingSubscription $subscription): ?BillingPlan
    {
        if ($subscription?->plan) {
            return $subscription->plan;
        }

        $planCode = $subscription?->plan_code ?: config('billing.default_plan', 'legacy_unlimited');

        return BillingPlan::query()
            ->where('code', $planCode)
            ->first();
    }

    private function fallbackPlan(string $planCode): BillingPlan
    {
        $plan = new BillingPlan();
        $plan->code = $planCode;
        $plan->name = $planCode === 'legacy_unlimited' ? 'Legacy Unlimited' : $planCode;
        $plan->is_active = true;
        $plan->metadata_json = [
            'description' => 'Без ограничений на время тестового периода',
            'currency' => 'RUB',
            'billing_period' => null,
            'system' => $planCode === 'legacy_unlimited',
            'limits' => array_fill_keys(BillingCodes::capabilities(), null),
        ];

        return $plan;
    }

    private function currentPlanPayload(BillingPlan $plan, bool $isDefault): array
    {
        $metadata = $plan->metadata_json ?? [];

        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $metadata['description'] ?? ($plan->code === 'legacy_unlimited' ? 'Без ограничений на время тестового периода' : null),
            'price' => isset($metadata['price_minor']) ? (int) $metadata['price_minor'] : null,
            'currency' => $metadata['currency'] ?? 'RUB',
            'billing_period' => $metadata['billing_period'] ?? null,
            'is_default' => $isDefault,
        ];
    }

    private function subscriptionPayload(?BillingSubscription $subscription): array
    {
        if (! $subscription) {
            return [
                'status' => 'active',
                'current_period_start' => null,
                'current_period_end' => null,
            ];
        }

        return [
            'status' => $subscription->status,
            'current_period_start' => $subscription->current_period_start?->toDateTimeString(),
            'current_period_end' => $subscription->current_period_end?->toDateTimeString(),
        ];
    }

    private function usagePayload(User $user, BillingPlan $plan): array
    {
        $limits = $plan->metadata_json['limits'] ?? [];
        $storageUsedMb = $this->storageUsedMb($user);

        $items = [
            [
                'code' => 'projects.active',
                'label' => 'Активные проекты',
                'used' => Project::query()
                    ->where('user_id', $user->id)
                    ->whereNull('archived_at')
                    ->count(),
                'limit' => $this->limitValue($limits, BillingCodes::CAP_PROJECTS_MAX_ACTIVE),
                'unit' => 'шт.',
                'period' => 'current',
            ],
            [
                'code' => 'pdf.generated',
                'label' => 'PDF-документы',
                'used' => $this->sumMonthlyUsage($user, [
                    BillingCodes::METRIC_PDF_SMETA_GENERATED,
                    BillingCodes::METRIC_PDF_REVISION_GENERATED,
                    BillingCodes::METRIC_PDF_PRICE_JUSTIFICATION_GENERATED,
                ]),
                'limit' => $this->limitValue($limits, BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT),
                'unit' => 'шт.',
                'period' => 'month',
            ],
            [
                'code' => 'evidence.runs',
                'label' => 'Проверки цен',
                'used' => $this->sumMonthlyUsage($user, [
                    BillingCodes::METRIC_EVIDENCE_RUNS_CREATED,
                    BillingCodes::METRIC_PDF_EVIDENCE_RUN_GENERATED,
                ]),
                'limit' => $this->limitValue($limits, BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT),
                'unit' => 'шт.',
                'period' => 'month',
            ],
            [
                'code' => 'chrome.captures',
                'label' => 'Скриншоты из расширения',
                'used' => $this->sumMonthlyUsage($user, [
                    BillingCodes::METRIC_CHROME_EXTRACT_WITH_EVIDENCE,
                    BillingCodes::METRIC_EVIDENCE_CHROME_CAPTURES,
                    BillingCodes::METRIC_EVIDENCE_CHROME_ITEM_CAPTURES,
                ]),
                'limit' => $this->limitValue($limits, BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT),
                'unit' => 'шт.',
                'period' => 'month',
            ],
        ];

        if ((bool) config('billing.storage_tracking_enabled', false) || $storageUsedMb > 0 || array_key_exists(BillingCodes::CAP_STORAGE_MAX_MB, $limits)) {
            $items[] = [
                'code' => 'storage.uploaded',
                'label' => 'Хранилище файлов',
                'used' => $storageUsedMb,
                'limit' => $this->limitValue($limits, BillingCodes::CAP_STORAGE_MAX_MB),
                'unit' => 'МБ',
                'period' => 'month',
            ];
        }

        return $items;
    }

    private function publicPlansPayload(string $currentPlanCode): array
    {
        return BillingPlan::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (BillingPlan $plan) => $this->isPublicPlan($plan))
            ->map(fn (BillingPlan $plan) => $this->publicPlanPayload($plan, $currentPlanCode))
            ->values()
            ->all();
    }

    private function publicPlanPayload(BillingPlan $plan, string $currentPlanCode): array
    {
        $metadata = $plan->metadata_json ?? [];
        $period = $metadata['billing_period'] ?? null;

        return [
            'code' => $plan->code,
            'name' => $this->publicPlanName($plan),
            'description' => $metadata['description'] ?? null,
            'price' => isset($metadata['price_minor']) ? (int) $metadata['price_minor'] : null,
            'price_minor' => isset($metadata['price_minor']) ? (int) $metadata['price_minor'] : null,
            'currency' => $metadata['currency'] ?? 'RUB',
            'billing_period' => $period,
            'period' => $period,
            'is_current' => $plan->code === $currentPlanCode,
            'is_available' => false,
            'features' => array_values(array_filter($metadata['features'] ?? [], fn ($feature) => is_string($feature) && $feature !== '')),
            'limits' => $this->publicLimits($metadata['limits'] ?? []),
        ];
    }

    private function publicPlanName(BillingPlan $plan): string
    {
        return match ($plan->code) {
            'sandbox_pro_month' => 'Профессиональный',
            'basic_month', 'basic_year' => 'Базовый',
            'pro_month', 'pro_year' => 'Профессиональный',
            'expert_month', 'expert_year' => 'Эксперт',
            default => $plan->name,
        };
    }

    private function isPublicPlan(BillingPlan $plan): bool
    {
        $metadata = $plan->metadata_json ?? [];

        return ! (bool) ($metadata['hidden'] ?? false)
            && ! (bool) ($metadata['sandbox'] ?? false)
            && ! (bool) ($metadata['system'] ?? false)
            && $plan->code !== 'legacy_unlimited';
    }

    private function publicLimits(array $limits): array
    {
        return collect($this->limitDefinitions())
            ->map(fn (array $definition) => [
                'code' => $definition['code'],
                'name' => $definition['label'],
                'label' => $definition['label'],
                'limit' => $this->limitValue($limits, $definition['capability']),
                'unit' => $definition['unit'],
            ])
            ->values()
            ->all();
    }

    private function limitDefinitions(): array
    {
        return [
            ['code' => 'projects.active', 'capability' => BillingCodes::CAP_PROJECTS_MAX_ACTIVE, 'label' => 'Активные проекты', 'unit' => 'шт.'],
            ['code' => 'pdf.generated', 'capability' => BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT, 'label' => 'PDF-документы', 'unit' => 'шт.'],
            ['code' => 'evidence.runs', 'capability' => BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT, 'label' => 'Проверки цен', 'unit' => 'шт.'],
            ['code' => 'chrome.captures', 'capability' => BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT, 'label' => 'Скриншоты из расширения', 'unit' => 'шт.'],
            ['code' => 'storage.uploaded', 'capability' => BillingCodes::CAP_STORAGE_MAX_MB, 'label' => 'Хранилище файлов', 'unit' => 'МБ'],
        ];
    }

    private function limitValue(array $limits, string $capability): ?int
    {
        if (! array_key_exists($capability, $limits) || $limits[$capability] === null || $limits[$capability] === '') {
            return null;
        }

        return (int) $limits[$capability];
    }

    private function sumMonthlyUsage(User $user, array $metricCodes): int
    {
        return (int) UsageEvent::query()
            ->where('user_id', $user->id)
            ->whereIn('metric_code', $metricCodes)
            ->whereBetween('occurred_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('quantity');
    }

    private function storageUsedMb(User $user): float|int
    {
        $bytes = (float) UsageEvent::query()
            ->where('user_id', $user->id)
            ->where('metric_code', BillingCodes::METRIC_STORAGE_BYTES_UPLOADED)
            ->whereBetween('occurred_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->sum('quantity');

        if ($bytes <= 0) {
            return 0;
        }

        $mb = round($bytes / 1024 / 1024, 2);

        return fmod($mb, 1.0) === 0.0 ? (int) $mb : $mb;
    }
}
