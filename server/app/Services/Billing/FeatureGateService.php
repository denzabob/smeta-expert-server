<?php

namespace App\Services\Billing;

use App\Models\FeatureEntitlement;
use App\Models\User;
use Throwable;

class FeatureGateService
{
    public function __construct(
        private readonly BillingContextResolver $contextResolver,
    ) {}

    public function allows(User $user, string $featureCode, array $context = []): bool
    {
        return $this->check($user, $featureCode, $context)->allowed;
    }

    public function check(User $user, string $featureCode, array $context = []): FeatureGateResult
    {
        $logOnly = (bool) config('billing.log_only', true);

        if (! (bool) config('billing.enabled', false)) {
            return new FeatureGateResult(true, $featureCode, 'billing_disabled', 'config', $logOnly);
        }

        try {
            $billingContext = $this->contextResolver->fromArray(array_merge($context, ['user' => $user]));
            $entitlement = $this->resolveEntitlement($billingContext, $featureCode);

            if (! $entitlement) {
                return new FeatureGateResult(true, $featureCode, 'missing_entitlement_allowed', 'fallback', $logOnly);
            }

            if ((bool) $entitlement->enabled) {
                return new FeatureGateResult(true, $featureCode, 'entitlement_allowed', $entitlement->source, $logOnly);
            }

            if ($logOnly) {
                return new FeatureGateResult(true, $featureCode, 'entitlement_denied_log_only', $entitlement->source, true);
            }

            return new FeatureGateResult(false, $featureCode, 'entitlement_denied', $entitlement->source, false);
        } catch (Throwable $e) {
            report($e);

            if ((bool) config('billing.fail_open', true)) {
                return new FeatureGateResult(true, $featureCode, 'fail_open_exception', 'exception', $logOnly);
            }

            return new FeatureGateResult(false, $featureCode, 'exception', 'exception', $logOnly);
        }
    }

    protected function resolveEntitlement(BillingContext $context, string $featureCode): ?FeatureEntitlement
    {
        return FeatureEntitlement::query()
            ->where('owner_type', $context->ownerType)
            ->where('owner_id', $context->ownerId)
            ->where('feature_code', $featureCode)
            ->activeForDate(now())
            ->latest('id')
            ->first();
    }
}
