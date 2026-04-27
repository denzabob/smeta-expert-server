<?php

namespace Tests\Unit\Billing;

use App\Models\FeatureEntitlement;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\BillingContext;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\FeatureGateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class FeatureGateServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_billing_disabled_allows_feature(): void
    {
        config(['billing.enabled' => false]);

        $user = User::factory()->create();
        $result = app(FeatureGateService::class)->check($user, BillingCodes::FEATURE_PDF_SMETA);

        $this->assertTrue($result->allowed);
        $this->assertSame('billing_disabled', $result->reason);
    }

    public function test_missing_subscription_or_entitlement_allows_feature(): void
    {
        config([
            'billing.enabled' => true,
            'billing.log_only' => false,
        ]);

        $user = User::factory()->create();
        $result = app(FeatureGateService::class)->check($user, BillingCodes::FEATURE_EVIDENCE_RUNS);

        $this->assertTrue($result->allowed);
        $this->assertSame('missing_entitlement_allowed', $result->reason);
    }

    public function test_exception_while_resolving_entitlement_allows_when_fail_open(): void
    {
        config([
            'billing.enabled' => true,
            'billing.fail_open' => true,
        ]);

        $service = new class(app(BillingContextResolver::class)) extends FeatureGateService
        {
            protected function resolveEntitlement(BillingContext $context, string $featureCode): ?FeatureEntitlement
            {
                throw new RuntimeException('Simulated entitlement failure.');
            }
        };

        $user = User::factory()->create();
        $result = $service->check($user, BillingCodes::FEATURE_PRICE_IMPORT);

        $this->assertTrue($result->allowed);
        $this->assertSame('fail_open_exception', $result->reason);
    }

    public function test_log_only_mode_does_not_deny_disabled_entitlement(): void
    {
        config([
            'billing.enabled' => true,
            'billing.log_only' => true,
        ]);

        $user = User::factory()->create();

        FeatureEntitlement::query()->create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'feature_code' => BillingCodes::FEATURE_CHROME_EXTRACT,
            'enabled' => false,
            'source' => 'override',
        ]);

        $result = app(FeatureGateService::class)->check($user, BillingCodes::FEATURE_CHROME_EXTRACT);

        $this->assertTrue($result->allowed);
        $this->assertTrue($result->logOnly);
        $this->assertSame('entitlement_denied_log_only', $result->reason);
    }
}
