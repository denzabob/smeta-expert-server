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

        if ($plan->exists) {
            return;
        }

        $plan->fill([
            'name' => 'Legacy Unlimited',
            'is_active' => true,
            'features_json' => array_fill_keys(BillingCodes::features(), true),
            'limits_json' => null,
            'metadata_json' => [
                'hidden' => true,
                'system' => true,
            ],
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
                ],
            ],
        );
    }
}
