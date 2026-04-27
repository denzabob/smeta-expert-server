<?php

namespace Database\Seeders;

use App\Models\BillingPlan;
use App\Services\Billing\BillingCodes;
use Illuminate\Database\Seeder;

class BillingPlanSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLegacyUnlimitedPlan();
        $this->seedSandboxProMonthPlan();
    }

    private function seedLegacyUnlimitedPlan(): void
    {
        $plan = BillingPlan::query()->firstOrNew([
            'code' => 'legacy_unlimited',
        ]);

        $plan->fill([
            'name' => $plan->exists ? $plan->name : 'Legacy Unlimited',
            'is_active' => $plan->exists ? $plan->is_active : true,
            'features_json' => $plan->features_json ?: array_fill_keys(BillingCodes::features(), true),
            'limits_json' => $plan->limits_json,
            'metadata_json' => array_merge($plan->metadata_json ?? [], [
                'hidden' => true,
                'system' => true,
                'limits' => $this->unlimitedLimits(),
            ]),
        ]);

        $plan->save();
    }

    private function seedSandboxProMonthPlan(): void
    {
        BillingPlan::query()->updateOrCreate(
            ['code' => 'sandbox_pro_month'],
            [
                'name' => 'Sandbox Pro Month',
                'is_active' => true,
                'features_json' => array_fill_keys(BillingCodes::features(), true),
                'limits_json' => null,
                'metadata_json' => [
                    'price_minor' => 10000,
                    'currency' => 'RUB',
                    'billing_period' => 'month',
                    'hidden' => true,
                    'sandbox' => true,
                    'limits' => [
                        BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 3,
                        BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 5,
                        BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT => 5,
                        BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT => 10,
                        BillingCodes::CAP_STORAGE_MAX_MB => 100,
                        BillingCodes::CAP_TEAM_MEMBERS_MAX_COUNT => 1,
                    ],
                ],
            ],
        );
    }

    private function unlimitedLimits(): array
    {
        return array_fill_keys(BillingCodes::capabilities(), null);
    }
}
