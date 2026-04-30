<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlan;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AdminBillingPlansTest extends TestCase
{
    use DatabaseTransactions;

    public function test_regular_user_cannot_access_billing_plan_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $plan = $this->makePlan();

        $this->actingAs($user, 'sanctum')->getJson('/api/admin/billing/plans')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson("/api/admin/billing/plans/{$plan->id}")->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson('/api/admin/billing/plans', $this->planPayload('user_blocked'))->assertForbidden();
        $this->actingAs($user, 'sanctum')->patchJson("/api/admin/billing/plans/{$plan->id}", ['name' => 'Blocked'])->assertForbidden();
    }

    public function test_admin_can_see_plans_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = $this->makePlan(code: 'visible_plan', name: 'Visible Plan');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/billing/plans')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $plan->id,
                'code' => 'visible_plan',
                'name' => 'Visible Plan',
            ]);
    }

    public function test_admin_can_create_plan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/plans', $this->planPayload('created_plan'))
            ->assertCreated()
            ->assertJsonPath('data.code', 'created_plan')
            ->assertJsonPath('data.metadata_json.price_minor', 299000)
            ->assertJsonPath('data.metadata_json.currency', 'RUB')
            ->assertJsonPath('data.metadata_json.billing_period', 'month');

        $this->assertSame(30, $response->json('data.metadata_json.limits')[BillingCodes::CAP_PROJECTS_MAX_ACTIVE]);

        $this->assertDatabaseHas('billing_plans', [
            'id' => $response->json('data.id'),
            'code' => 'created_plan',
            'name' => 'Created plan',
        ]);

        $plan = BillingPlan::query()->where('code', 'created_plan')->firstOrFail();
        $this->assertSame(30, $plan->metadata_json['limits'][BillingCodes::CAP_PROJECTS_MAX_ACTIVE]);
    }

    public function test_admin_can_create_public_plan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/plans', [
                ...$this->planPayload('public_created_plan'),
                'hidden' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_public', true)
            ->assertJsonPath('data.metadata_json.hidden', false);
    }

    public function test_incomplete_plan_cannot_be_published(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = BillingPlan::query()->create([
            'code' => 'draft_plan',
            'name' => 'Draft plan',
            'is_active' => true,
            'metadata_json' => [
                'hidden' => true,
                'sandbox' => false,
                'system' => false,
                'limits' => array_fill_keys(BillingCodes::capabilities(), null),
            ],
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/billing/plans/{$plan->id}", [
                'hidden' => false,
            ])
            ->assertUnprocessable();
    }

    public function test_code_must_be_unique(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->makePlan(code: 'duplicate_plan');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/plans', $this->planPayload('duplicate_plan'))
            ->assertUnprocessable();
    }

    public function test_admin_can_update_price_limits_name_and_active_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = $this->makePlan(code: 'editable_plan');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/billing/plans/{$plan->id}", [
                'name' => 'Edited plan',
                'is_active' => false,
                'price_minor' => 499000,
                'currency' => 'RUB',
                'billing_period' => 'year',
                'limits' => [
                    BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 40,
                    BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 200,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Edited plan')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.metadata_json.price_minor', 499000)
            ->assertJsonPath('data.metadata_json.billing_period', 'year');

        $plan->refresh();
        $this->assertSame(40, $plan->metadata_json['limits'][BillingCodes::CAP_PROJECTS_MAX_ACTIVE]);
        $this->assertSame(200, $plan->metadata_json['limits'][BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT]);
    }

    public function test_code_cannot_be_changed_with_patch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $plan = $this->makePlan(code: 'immutable_code');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/billing/plans/{$plan->id}", [
                'code' => 'changed_code',
                'name' => 'Should fail',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('billing_plans', [
            'id' => $plan->id,
            'code' => 'immutable_code',
        ]);
    }

    public function test_legacy_unlimited_cannot_be_made_inactive(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $legacy = $this->makeLegacyUnlimitedPlan();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/billing/plans/{$legacy->id}", [
                'is_active' => false,
            ])
            ->assertUnprocessable();
    }

    public function test_legacy_unlimited_cannot_be_made_paid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $legacy = $this->makeLegacyUnlimitedPlan();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/billing/plans/{$legacy->id}", [
                'price_minor' => 100,
            ])
            ->assertUnprocessable();
    }

    public function test_legacy_unlimited_cannot_have_limited_capabilities(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $legacy = $this->makeLegacyUnlimitedPlan();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/billing/plans/{$legacy->id}", [
                'limits' => [
                    BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 1,
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_missing_limit_keys_are_normalized_to_null(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/plans', [
                ...$this->planPayload('partial_limits'),
                'limits' => [
                    BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 10,
                ],
            ])
            ->assertCreated();

        $limits = BillingPlan::query()->where('code', 'partial_limits')->firstOrFail()->metadata_json['limits'];

        $this->assertSame(10, $limits[BillingCodes::CAP_PROJECTS_MAX_ACTIVE]);
        $this->assertNull($limits[BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT]);
        $this->assertNull($limits[BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT]);
        $this->assertNull($limits[BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT]);
        $this->assertNull($limits[BillingCodes::CAP_STORAGE_MAX_MB]);
        $this->assertNull($limits[BillingCodes::CAP_TEAM_MEMBERS_MAX_COUNT]);
    }

    public function test_empty_string_limits_become_null(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/billing/plans', [
                ...$this->planPayload('empty_limits'),
                'limits' => [
                    BillingCodes::CAP_PROJECTS_MAX_ACTIVE => '',
                    BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 5,
                ],
            ])
            ->assertCreated();

        $limits = BillingPlan::query()->where('code', 'empty_limits')->firstOrFail()->metadata_json['limits'];

        $this->assertNull($limits[BillingCodes::CAP_PROJECTS_MAX_ACTIVE]);
        $this->assertSame(5, $limits[BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT]);
    }

    private function makePlan(string $code = 'test_plan', string $name = 'Test plan'): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'metadata_json' => $this->planPayload($code),
        ]);
    }

    private function makeLegacyUnlimitedPlan(): BillingPlan
    {
        return BillingPlan::query()->create([
            'code' => 'legacy_unlimited',
            'name' => 'Legacy Unlimited',
            'is_active' => true,
            'metadata_json' => [
                'price_minor' => 0,
                'currency' => 'RUB',
                'billing_period' => 'custom',
                'hidden' => true,
                'system' => true,
                'features' => ['Legacy unlimited access'],
                'limits' => array_fill_keys(BillingCodes::capabilities(), null),
            ],
        ]);
    }

    private function planPayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Created plan',
            'price_minor' => 299000,
            'currency' => 'RUB',
            'billing_period' => 'month',
            'is_active' => true,
            'hidden' => true,
            'sandbox' => false,
            'system' => false,
            'sort_order' => 20,
            'description' => 'Для индивидуального эксперта',
            'features' => [
                'До 30 активных проектов',
                'PDF-выгрузки',
            ],
            'limits' => [
                BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 30,
                BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 100,
                BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT => 50,
                BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT => 300,
                BillingCodes::CAP_STORAGE_MAX_MB => 5120,
                BillingCodes::CAP_TEAM_MEMBERS_MAX_COUNT => 1,
            ],
        ];
    }
}
