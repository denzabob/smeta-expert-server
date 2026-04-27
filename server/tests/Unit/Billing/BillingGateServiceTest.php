<?php

namespace Tests\Unit\Billing;

use App\Models\BillingGateEvent;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use App\Services\Billing\BillingGateService;
use App\Services\Billing\DTO\BillingGateResult;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class BillingGateServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_returns_allowed_when_billing_disabled(): void
    {
        config(['billing.enabled' => false]);

        $user = User::factory()->create();
        $this->makePlan('limited', [BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 1]);
        $this->makeSubscription($user, 'limited');

        $result = app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT,
            ['usage' => 1],
        );

        $this->assertTrue($result->allowed);
        $this->assertTrue($result->wouldBlock);
    }

    public function test_returns_allowed_and_would_block_in_log_only_mode_when_usage_reaches_limit(): void
    {
        config([
            'billing.enabled' => true,
            'billing.log_only' => true,
            'billing.enforce_limits' => false,
        ]);

        $user = User::factory()->create();
        $this->makePlan('limited', [BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 5]);
        $this->makeSubscription($user, 'limited');

        $result = app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT,
            ['usage' => 5],
        );

        $this->assertTrue($result->allowed);
        $this->assertTrue($result->logOnly);
        $this->assertTrue($result->wouldBlock);
        $this->assertSame(5, $result->limit);
        $this->assertSame(5, $result->usage);
    }

    public function test_creates_gate_event_when_would_block(): void
    {
        config([
            'billing.enabled' => true,
            'billing.log_only' => true,
        ]);

        $user = User::factory()->create();
        $this->makePlan('limited', [BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT => 10]);
        $this->makeSubscription($user, 'limited');

        app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT,
            ['usage' => 10, 'source' => 'unit_test'],
        );

        $this->assertDatabaseHas('billing_gate_events', [
            'user_id' => $user->id,
            'plan_code' => 'limited',
            'capability' => BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT,
            'limit_value' => 10,
            'usage_value' => 10,
            'would_block' => true,
            'enforced' => false,
        ]);
    }

    public function test_does_not_create_gate_event_when_under_limit(): void
    {
        $user = User::factory()->create();
        $this->makePlan('limited', [BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT => 5]);
        $this->makeSubscription($user, 'limited');

        app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT,
            ['usage' => 4],
        );

        $this->assertSame(0, BillingGateEvent::query()->count());
    }

    public function test_legacy_unlimited_never_would_blocks(): void
    {
        config(['billing.default_plan' => 'legacy_unlimited']);

        $user = User::factory()->create();
        $this->makePlan('legacy_unlimited', $this->unlimitedLimits());

        $result = app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT,
            ['usage' => 999],
        );

        $this->assertTrue($result->allowed);
        $this->assertFalse($result->wouldBlock);
        $this->assertNull($result->limit);
    }

    public function test_missing_subscription_falls_back_to_default_plan(): void
    {
        config(['billing.default_plan' => 'fallback_limited']);

        $user = User::factory()->create();
        $this->makePlan('fallback_limited', [BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 3]);

        $result = app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT,
            ['usage' => 3],
        );

        $this->assertSame('fallback_limited', $result->planCode);
        $this->assertTrue($result->wouldBlock);
    }

    public function test_missing_limit_means_unlimited(): void
    {
        $user = User::factory()->create();
        $this->makePlan('no_limit', []);
        $this->makeSubscription($user, 'no_limit');

        $result = app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT,
            ['usage' => 100],
        );

        $this->assertTrue($result->allowed);
        $this->assertFalse($result->wouldBlock);
        $this->assertNull($result->limit);
    }

    public function test_force_log_creates_event_even_when_not_would_block(): void
    {
        $user = User::factory()->create();
        $this->makePlan('limited', [BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 5]);
        $this->makeSubscription($user, 'limited');

        app(BillingGateService::class)->check(
            $user,
            BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT,
            ['usage' => 1, 'force_log' => true],
        );

        $this->assertDatabaseHas('billing_gate_events', [
            'user_id' => $user->id,
            'capability' => BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT,
            'limit_value' => 5,
            'usage_value' => 1,
            'would_block' => false,
        ]);
    }

    public function test_fail_open_returns_allowed_result(): void
    {
        config(['billing.fail_open' => true]);

        $service = new class extends BillingGateService
        {
            protected function resolvePlan(User $user): ?BillingPlan
            {
                throw new RuntimeException('Simulated gate failure.');
            }
        };

        $result = $service->check(User::factory()->create(), BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT);

        $this->assertTrue($result->allowed);
        $this->assertFalse($result->wouldBlock);
        $this->assertSame('fail_open', $result->reason);
    }

    public function test_result_to_array_returns_expected_keys(): void
    {
        $result = new BillingGateResult(
            allowed: true,
            logOnly: true,
            planCode: 'legacy_unlimited',
            capability: BillingCodes::CAP_PROJECTS_MAX_ACTIVE,
            limit: null,
            usage: 2,
            wouldBlock: false,
            enforced: false,
            reason: 'allowed',
        );

        $this->assertSame([
            'allowed' => true,
            'log_only' => true,
            'plan_code' => 'legacy_unlimited',
            'capability' => BillingCodes::CAP_PROJECTS_MAX_ACTIVE,
            'limit' => null,
            'usage' => 2,
            'would_block' => false,
            'enforced' => false,
            'reason' => 'allowed',
        ], $result->toArray());
    }

    public function test_projects_max_active_counts_active_projects(): void
    {
        $user = User::factory()->create();
        $this->makePlan('limited', [BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 2]);
        $this->makeSubscription($user, 'limited');

        Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('GATE-ACTIVE-'),
            'expert_name' => 'Gate Expert',
            'address' => 'Gate address',
        ]);

        Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('GATE-ACTIVE-'),
            'expert_name' => 'Gate Expert',
            'address' => 'Gate address',
        ]);

        $archivedProject = Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('GATE-ARCHIVED-'),
            'expert_name' => 'Gate Expert',
            'address' => 'Gate address',
        ]);
        $archivedProject->forceFill(['archived_at' => now()])->save();

        $result = app(BillingGateService::class)->check($user, BillingCodes::CAP_PROJECTS_MAX_ACTIVE);

        $this->assertSame(2, $result->usage);
        $this->assertTrue($result->wouldBlock);
    }

    private function makePlan(string $code, array $limits): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'metadata_json' => [
                'limits' => $limits,
            ],
        ]);
    }

    private function makeSubscription(User $user, string $planCode): BillingSubscription
    {
        $plan = BillingPlan::query()->where('code', $planCode)->firstOrFail();

        return BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'source' => 'test',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function unlimitedLimits(): array
    {
        return array_fill_keys(BillingCodes::capabilities(), null);
    }
}
