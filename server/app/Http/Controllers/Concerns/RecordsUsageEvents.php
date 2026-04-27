<?php

namespace App\Http\Controllers\Concerns;

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
}
