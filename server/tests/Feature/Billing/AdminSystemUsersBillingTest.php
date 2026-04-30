<?php

namespace Tests\Feature\Billing;

use App\Models\BillingGateEvent;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\BillingSubscriptionEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminSystemUsersBillingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_users_list_contains_billing_summary(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user', 'email' => 'billing-summary@example.com']);
        $plan = $this->makePlan('expert_pro', 'Expert Pro');

        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'source' => 'manual',
            'current_period_start' => now()->subDay(),
            'current_period_end' => null,
        ]);

        BillingGateEvent::query()->create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'capability' => 'projects.max_active',
            'limit_value' => 1,
            'usage_value' => 2,
            'would_block' => true,
            'enforced' => false,
            'context_json' => ['action' => 'project.create'],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/system/users?search=billing-summary@example.com')
            ->assertOk()
            ->assertJsonPath('users.0.billing.plan_code', 'expert_pro')
            ->assertJsonPath('users.0.billing.plan_name', 'Expert Pro')
            ->assertJsonPath('users.0.billing.subscription_status', 'active')
            ->assertJsonPath('users.0.billing.current_period_end', null)
            ->assertJsonPath('users.0.billing.gate_events_count', 1)
            ->assertJsonPath('users.0.billing.would_block_events_count', 1);
    }

    public function test_admin_user_card_contains_billing_history_and_gate_stats(): void
    {
        Carbon::setTestNow('2026-04-30 12:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $plan = $this->makePlan('expert_pro', 'Expert Pro');

        $subscription = BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code,
            'status' => 'active',
            'source' => 'manual',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        BillingSubscriptionEvent::query()->create([
            'user_id' => $user->id,
            'admin_user_id' => $admin->id,
            'subscription_id' => $subscription->id,
            'event_type' => 'assigned',
            'new_plan_code' => $plan->code,
            'new_status' => 'active',
            'new_period_end' => $subscription->current_period_end,
            'reason' => 'Тестовый доступ',
        ]);

        BillingGateEvent::query()->create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'capability' => 'projects.max_active',
            'limit_value' => 1,
            'usage_value' => 2,
            'would_block' => true,
            'enforced' => false,
            'context_json' => ['action' => 'project.create'],
            'created_at' => now()->subDay(),
        ]);
        BillingGateEvent::query()->create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'capability' => 'pdf_exports.monthly_limit',
            'limit_value' => 1,
            'usage_value' => 1,
            'would_block' => true,
            'enforced' => false,
            'context_json' => ['action' => 'pdf.generate'],
            'created_at' => now(),
        ]);
        BillingGateEvent::query()->create([
            'user_id' => $user->id,
            'plan_code' => $plan->code,
            'capability' => 'projects.max_active',
            'limit_value' => 1,
            'usage_value' => 3,
            'would_block' => true,
            'enforced' => false,
            'context_json' => ['action' => 'project.create'],
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/system/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('billing.subscription.plan_code', 'expert_pro')
            ->assertJsonPath('billing.gate_stats.total_events', 3)
            ->assertJsonPath('billing.gate_stats.current_month_events', 3)
            ->assertJsonPath('billing.gate_stats.last_7_days_events', 3)
            ->assertJsonPath('billing.gate_stats.top_actions.0.action', 'project.create')
            ->assertJsonPath('billing.history.0.event_type', 'assigned')
            ->assertJsonPath('billing.history.0.admin_user.id', $admin->id);
    }

    private function makePlan(string $code, string $name): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 99000,
                'currency' => 'RUB',
                'billing_period' => 'month',
                'limits' => [],
            ],
        ]);
    }
}
