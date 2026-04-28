<?php

namespace Tests\Feature\Billing;

use App\Models\BillingGateEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminBillingGateEventsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_user_cannot_access_gate_events_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        foreach ([
            '/api/admin/billing/gate-events',
            '/api/admin/billing/gate-events/summary',
        ] as $endpoint) {
            $this->actingAs($user, 'sanctum')
                ->getJson($endpoint)
                ->assertForbidden();
        }
    }

    public function test_admin_can_see_gate_events_list(): void
    {
        [$admin, $user] = $this->makeUsers();
        $event = $this->makeGateEvent($user, capability: 'projects.max_active');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/gate-events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.user.id', $user->id)
            ->assertJsonPath('data.0.user.email', $user->email)
            ->assertJsonPath('data.0.capability', 'projects.max_active')
            ->assertJsonPath('data.0.would_block', true)
            ->assertJsonPath('data.0.enforced', false);
    }

    public function test_would_block_filter_works(): void
    {
        [$admin, $user] = $this->makeUsers();
        $blocked = $this->makeGateEvent($user, capability: 'projects.max_active', wouldBlock: true);
        $this->makeGateEvent($user, capability: 'pdf_exports.monthly_limit', wouldBlock: false);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/gate-events?would_block=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $blocked->id)
            ->assertJsonPath('data.0.would_block', true);
    }

    public function test_capability_filter_works(): void
    {
        [$admin, $user] = $this->makeUsers();
        $event = $this->makeGateEvent($user, capability: 'pdf_exports.monthly_limit');
        $this->makeGateEvent($user, capability: 'projects.max_active');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/gate-events?capability=pdf_exports.monthly_limit')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.capability', 'pdf_exports.monthly_limit');
    }

    public function test_summary_counts_total_would_block_and_enforced(): void
    {
        [$admin, $user] = $this->makeUsers();

        $this->makeGateEvent($user, capability: 'pdf_exports.monthly_limit', wouldBlock: true, enforced: false);
        $this->makeGateEvent($user, capability: 'pdf_exports.monthly_limit', wouldBlock: true, enforced: true);
        $this->makeGateEvent($user, capability: 'projects.max_active', wouldBlock: false, enforced: false);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/gate-events/summary')
            ->assertOk()
            ->assertJsonPath('total_events', 3)
            ->assertJsonPath('would_block_events', 2)
            ->assertJsonPath('enforced_events', 1)
            ->assertJsonPath('top_capabilities.0.capability', 'pdf_exports.monthly_limit')
            ->assertJsonPath('top_capabilities.0.count', 2)
            ->assertJsonPath('top_users.0.user_id', $user->id)
            ->assertJsonPath('top_users.0.count', 3);
    }

    public function test_response_does_not_contain_secret_context_values(): void
    {
        [$admin, $user] = $this->makeUsers();

        $this->makeGateEvent($user, context: [
            'action' => 'project.create',
            'secret_key' => 'super-secret',
            'nested' => [
                'token' => 'hidden-token',
                'safe' => 'visible',
            ],
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/gate-events')
            ->assertOk()
            ->assertJsonPath('data.0.context_json.action', 'project.create')
            ->assertJsonPath('data.0.context_json.nested.safe', 'visible');

        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('hidden-token', $response->getContent());
    }

    public function test_pagination_works(): void
    {
        [$admin, $user] = $this->makeUsers();

        foreach (range(1, 3) as $index) {
            $this->makeGateEvent($user, capability: 'projects.max_active', context: ['index' => $index]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/gate-events?per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    private function makeUsers(): array
    {
        return [
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'user']),
        ];
    }

    private function makeGateEvent(
        User $user,
        string $capability = 'projects.max_active',
        bool $wouldBlock = true,
        bool $enforced = false,
        array $context = ['action' => 'project.create'],
    ): BillingGateEvent {
        return BillingGateEvent::query()->create([
            'user_id' => $user->id,
            'plan_code' => 'sandbox_pro_month',
            'capability' => $capability,
            'limit_value' => 3,
            'usage_value' => 3,
            'would_block' => $wouldBlock,
            'enforced' => $enforced,
            'context_json' => $context,
        ]);
    }
}
