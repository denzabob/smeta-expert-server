<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BillingCapabilitiesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'billing' => [
                'enabled' => (bool) config('billing.enabled', false),
                'mode' => (string) config('billing.mode', 'off'),
                'adminUiEnabled' => (bool) config('billing.admin_ui_enabled', false),
                'userUiEnabled' => (bool) config('billing.user_ui_enabled', false),
                'checkoutEnabled' => (bool) config('billing.checkout_enabled', false),
                'paymentsEnabled' => (bool) config('billing.payments.enabled', false),
                'enforcementEnabled' => (bool) config('billing.enforcement_enabled', false),
                'usageTrackingEnabled' => (bool) config('billing.usage_tracking_enabled', false),
                'provider' => (string) config('billing.payments.default_provider', 'yookassa'),
                'providerMode' => (string) config('billing.provider_mode', 'test'),
                'defaultPlan' => (string) config('billing.default_plan', 'legacy_unlimited'),
                'failOpen' => (bool) config('billing.fail_open', true),
            ],
        ]);
    }
}
