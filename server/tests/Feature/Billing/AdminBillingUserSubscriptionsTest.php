<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminBillingUserSubscriptionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_user_cannot_access_subscription_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $target = User::factory()->create(['role' => 'user']);

        foreach ([
            ['getJson', "/api/admin/billing/users/{$target->id}/subscription"],
            ['postJson', "/api/admin/billing/users/{$target->id}/subscription/assign"],
            ['postJson', "/api/admin/billing/users/{$target->id}/subscription/extend"],
            ['postJson', "/api/admin/billing/users/{$target->id}/subscription/cancel"],
            ['postJson', "/api/admin/billing/users/{$target->id}/subscription/legacy"],
            ['getJson', "/api/admin/billing/users/{$target->id}/subscription/history"],
        ] as [$method, $endpoint]) {
            $this->actingAs($user, 'sanctum')
                ->{$method}($endpoint)
                ->assertForbidden();
        }
    }

    public function test_admin_can_view_user_subscription(): void
    {
        [$admin, $user] = $this->makeUsers();
        $plan = $this->makePlan('pro_view');
        $subscription = $this->makeSubscription($user, $plan);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/users/{$user->id}/subscription")
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('subscription.id', $subscription->id)
            ->assertJsonPath('subscription.plan_code', 'pro_view')
            ->assertJsonPath('plan.code', 'pro_view');
    }

    public function test_admin_can_assign_plan_to_user(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');
        [$admin, $user] = $this->makeUsers();
        $this->makePlan('pro_assign');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/assign", [
                'plan_code' => 'pro_assign',
                'period' => 'month',
                'reason' => 'Тестовый доступ',
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.plan_code', 'pro_assign')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.source', 'manual');

        $this->assertDatabaseHas('billing_subscriptions', [
            'user_id' => $user->id,
            'plan_code' => 'pro_assign',
            'status' => 'active',
            'source' => 'manual',
        ]);
        $this->assertDatabaseHas('billing_subscription_events', [
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'event_type' => 'assigned',
            'new_plan_code' => 'pro_assign',
        ]);

        $subscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('plan_code', 'pro_assign')
            ->firstOrFail();
        $this->assertSame('admin_manual', $subscription->overrides_json['source'] ?? null);
        $this->assertSame($admin->id, $subscription->overrides_json['assigned_by'] ?? null);
    }

    public function test_assign_closes_previous_active_subscription(): void
    {
        [$admin, $user] = $this->makeUsers();
        $oldPlan = $this->makePlan('old_plan');
        $this->makePlan('new_plan');
        $oldSubscription = $this->makeSubscription($user, $oldPlan);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/assign", [
                'plan_code' => 'new_plan',
                'period' => 'month',
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.plan_code', 'new_plan');

        $this->assertSame('replaced', $oldSubscription->refresh()->status);
        $this->assertDatabaseHas('billing_subscription_events', [
            'subscription_id' => $oldSubscription->id,
            'event_type' => 'replaced',
            'old_plan_code' => 'old_plan',
            'new_plan_code' => 'new_plan',
        ]);
    }

    public function test_assign_custom_without_ends_at_creates_indefinite_subscription(): void
    {
        [$admin, $user] = $this->makeUsers();
        $this->makePlan('custom_plan');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/assign", [
                'plan_code' => 'custom_plan',
                'period' => 'custom',
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.plan_code', 'custom_plan')
            ->assertJsonPath('subscription.current_period_end', null);

        $this->assertDatabaseHas('billing_subscriptions', [
            'user_id' => $user->id,
            'plan_code' => 'custom_plan',
            'status' => 'active',
            'current_period_end' => null,
        ]);
    }

    public function test_extend_extends_active_subscription(): void
    {
        Carbon::setTestNow('2026-04-28 10:00:00');
        [$admin, $user] = $this->makeUsers();
        $plan = $this->makePlan('extend_plan');
        $subscription = $this->makeSubscription($user, $plan, periodEnd: now()->addMonth());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/extend", [
                'months' => 1,
                'days' => 2,
                'reason' => 'Продление',
            ])
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->id);

        $this->assertSame(
            now()->addMonths(2)->addDays(2)->toDateTimeString(),
            $subscription->refresh()->current_period_end?->toDateTimeString(),
        );
        $this->assertDatabaseHas('billing_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'extended',
        ]);
    }

    public function test_extend_without_active_subscription_returns_422(): void
    {
        [$admin, $user] = $this->makeUsers();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/extend", [
                'months' => 1,
            ])
            ->assertUnprocessable();
    }

    public function test_cancel_changes_status_to_canceled(): void
    {
        [$admin, $user] = $this->makeUsers();
        $plan = $this->makePlan('cancel_plan');
        $subscription = $this->makeSubscription($user, $plan);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/cancel", [
                'reason' => 'Отмена тестового доступа',
            ])
            ->assertOk()
            ->assertJsonPath('subscription.status', 'canceled');

        $this->assertSame('canceled', $subscription->refresh()->status);
        $this->assertDatabaseHas('billing_subscription_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'canceled',
        ]);
    }

    public function test_cancel_without_active_subscription_returns_422(): void
    {
        [$admin, $user] = $this->makeUsers();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/cancel")
            ->assertUnprocessable();
    }

    public function test_switch_to_legacy_creates_active_legacy_unlimited_subscription(): void
    {
        [$admin, $user] = $this->makeUsers();
        $plan = $this->makePlan('paid_before_legacy');
        $this->makeLegacyPlan();
        $oldSubscription = $this->makeSubscription($user, $plan);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/legacy", [
                'reason' => 'Вернуть безлимитный тестовый режим',
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.plan_code', 'legacy_unlimited')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonPath('subscription.current_period_end', null);

        $this->assertSame('replaced', $oldSubscription->refresh()->status);
        $this->assertDatabaseHas('billing_subscriptions', [
            'user_id' => $user->id,
            'plan_code' => 'legacy_unlimited',
            'status' => 'active',
        ]);
    }

    public function test_history_returns_subscription_events(): void
    {
        [$admin, $user] = $this->makeUsers();
        $plan = $this->makePlan('history_plan');
        $subscription = $this->makeSubscription($user, $plan);

        BillingSubscriptionEvent::query()->create([
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'subscription_id' => $subscription->id,
            'event_type' => 'assigned',
            'new_plan_code' => 'history_plan',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/users/{$user->id}/subscription/history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', 'assigned')
            ->assertJsonPath('data.0.admin_user.id', $admin->id);
    }

    public function test_audit_events_are_created_for_assign_extend_cancel_and_legacy(): void
    {
        [$admin, $user] = $this->makeUsers();
        $this->makePlan('audit_plan');
        $this->makeLegacyPlan();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/assign", [
                'plan_code' => 'audit_plan',
                'period' => 'month',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/extend", [
                'days' => 1,
            ])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/cancel")
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/billing/users/{$user->id}/subscription/legacy")
            ->assertCreated();

        $eventTypes = BillingSubscriptionEvent::query()
            ->where('user_id', $user->id)
            ->pluck('event_type');

        $this->assertTrue($eventTypes->contains('assigned'));
        $this->assertTrue($eventTypes->contains('extended'));
        $this->assertTrue($eventTypes->contains('canceled'));
        $this->assertTrue($eventTypes->contains('switched_to_legacy'));
    }

    public function test_checkout_and_enforcement_flags_are_not_changed(): void
    {
        $this->assertFalse((bool) config('billing.enabled'));
        $this->assertFalse((bool) config('billing.enforce_limits'));
        $this->assertFalse((bool) config('billing.payments.checkout_ui_enabled'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUsers(): array
    {
        return [
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'user']),
        ];
    }

    private function makePlan(string $code): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
            ],
        ]);
    }

    private function makeLegacyPlan(): BillingPlan
    {
        return BillingPlan::query()->updateOrCreate(
            ['code' => 'legacy_unlimited'],
            [
                'name' => 'Legacy Unlimited',
                'is_active' => true,
                'metadata_json' => [
                    'price_minor' => 0,
                    'currency' => 'RUB',
                    'billing_period' => 'custom',
                    'hidden' => true,
                    'system' => true,
                ],
            ],
        );
    }

    private function makeSubscription(
        User $user,
        BillingPlan $plan,
        ?Carbon $periodEnd = null,
    ): BillingSubscription {
        return BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'source' => 'manual',
            'current_period_start' => now(),
            'current_period_end' => $periodEnd ?? now()->addMonth(),
        ]);
    }
}
