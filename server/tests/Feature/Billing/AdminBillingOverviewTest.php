<?php

namespace Tests\Feature\Billing;

use App\Models\Project;
use App\Models\UsageCounter;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminBillingOverviewTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_user_cannot_access_admin_billing_overview(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/billing/overview')
            ->assertStatus(403);
    }

    public function test_admin_can_access_overview_totals(): void
    {
        [$admin, $user, $project] = $this->makeBillingFixture();

        UsageEvent::query()->create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'metric_code' => BillingCodes::METRIC_PDF_SMETA_GENERATED,
            'feature_code' => BillingCodes::FEATURE_PDF_SMETA,
            'quantity' => 2,
            'unit' => 'count',
            'source' => 'api',
            'occurred_at' => now(),
        ]);

        UsageEvent::query()->create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'user_id' => $user->id,
            'project_id' => $project->id,
            'metric_code' => BillingCodes::METRIC_STORAGE_BYTES_UPLOADED,
            'feature_code' => BillingCodes::FEATURE_EVIDENCE_ASSETS,
            'quantity' => 2048,
            'unit' => 'bytes',
            'source' => 'api',
            'occurred_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/overview')
            ->assertOk()
            ->assertJsonStructure([
                'period' => ['start', 'end'],
                'totals' => [
                    'users',
                    'active_projects',
                    'usage_events',
                    'storage_bytes_uploaded',
                    'pdf_smeta_generated',
                    'pdf_price_justification_generated',
                    'pdf_evidence_run_generated',
                    'evidence_runs_created',
                    'chrome_extract_with_evidence',
                ],
                'top_metrics',
                'recent_events',
                'storage',
            ])
            ->assertJsonPath('totals.pdf_smeta_generated', 2)
            ->assertJsonPath('totals.storage_bytes_uploaded', 2048);
    }

    public function test_user_overview_without_subscription_returns_legacy_unlimited_fallback(): void
    {
        [$admin, $user] = $this->makeBillingFixture();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/billing/users/{$user->id}/overview")
            ->assertOk()
            ->assertJsonPath('billing.plan_code', 'legacy_unlimited')
            ->assertJsonPath('billing.subscription_status', 'fallback')
            ->assertJsonPath('billing.source', 'fallback');
    }

    public function test_usage_endpoint_filters_by_metric_code(): void
    {
        [$admin, $user] = $this->makeBillingFixture();
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        UsageCounter::query()->create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'metric_code' => BillingCodes::METRIC_PDF_SMETA_GENERATED,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'quantity' => 7,
        ]);

        UsageCounter::query()->create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'metric_code' => BillingCodes::METRIC_PROJECTS_CREATED,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'quantity' => 3,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/usage?metric_code=' . BillingCodes::METRIC_PDF_SMETA_GENERATED);

        $response->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.metric_code', BillingCodes::METRIC_PDF_SMETA_GENERATED)
            ->assertJsonPath('items.0.quantity', 7);
    }

    public function test_events_endpoint_limits_and_returns_latest_events(): void
    {
        [$admin, $user, $project] = $this->makeBillingFixture();

        foreach ([1, 2, 3] as $index) {
            UsageEvent::query()->create([
                'owner_type' => 'user',
                'owner_id' => $user->id,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'metric_code' => BillingCodes::METRIC_CHROME_EXTRACT_WITH_EVIDENCE,
                'feature_code' => BillingCodes::FEATURE_CHROME_EXTRACT_WITH_EVIDENCE,
                'quantity' => 1,
                'unit' => 'count',
                'source' => 'chrome_extension',
                'occurred_at' => now()->subMinutes(3 - $index),
            ]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/events?limit=2')
            ->assertOk()
            ->assertJsonPath('limit', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.source', 'chrome_extension');
    }

    private function makeBillingFixture(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $project = Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('BILL-ADMIN-'),
            'expert_name' => 'Billing Admin',
            'address' => 'Billing address',
        ]);

        return [$admin, $user, $project];
    }
}
