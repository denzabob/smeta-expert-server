<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Billing\BillingContextResolver;
use App\Services\Billing\BillingUsageExclusionService;
use App\Services\Billing\UsageTracker;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class UsageTrackerDoesNotBreakFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_usage_tracker_exception_does_not_break_project_create_flow(): void
    {
        $this->app->instance(
            UsageTracker::class,
            new class(app(BillingContextResolver::class), app(BillingUsageExclusionService::class)) extends UsageTracker
            {
                public function record(string $metricCode, int|float $quantity = 1, array $context = []): void
                {
                    throw new RuntimeException('Simulated usage tracking failure.');
                }
            }
        );

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/projects', [
                'number' => uniqid('BILL-FAIL-OPEN-'),
                'expert_name' => 'Billing Expert',
                'address' => 'Billing address',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('user_id', $user->id);
    }
}
