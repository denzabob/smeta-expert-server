<?php

namespace App\Services\Billing;

use App\Models\User;
use Throwable;

class QuotaService
{
    public function check(User $user, string $metricCode, int|float $quantity = 1, array $context = []): QuotaResult
    {
        if (! (bool) config('billing.enforce_limits', false)) {
            return new QuotaResult(true, $metricCode, (float) $quantity, 'enforcement_disabled', false);
        }

        try {
            return new QuotaResult(true, $metricCode, (float) $quantity, 'limit_enforcement_not_implemented', true);
        } catch (Throwable $e) {
            report($e);

            if ((bool) config('billing.fail_open', true)) {
                return new QuotaResult(true, $metricCode, (float) $quantity, 'fail_open_exception', true);
            }

            return new QuotaResult(false, $metricCode, (float) $quantity, 'exception', true);
        }
    }
}
