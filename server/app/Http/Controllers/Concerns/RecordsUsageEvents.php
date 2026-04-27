<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Billing\BillingGateService;
use App\Services\Billing\UsageTracker;
use Throwable;

trait RecordsUsageEvents
{
    private function recordUsageEvent(string $metricCode, int|float $quantity = 1, array $context = []): void
    {
        try {
            app(UsageTracker::class)->record($metricCode, $quantity, $context);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function checkBillingGateSafely(?User $user, string $capability, array $context = []): void
    {
        if (! $user) {
            return;
        }

        try {
            app(BillingGateService::class)->check($user, $capability, array_merge([
                'route' => request()?->route()?->uri(),
            ], $context));
        } catch (Throwable $e) {
            logger()->warning('Billing gate hook failed', [
                'capability' => $capability,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
