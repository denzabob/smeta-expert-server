<?php

namespace Tests\Feature\Billing;

use App\Models\BillingGateEvent;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Models\Project;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Billing\BillingCodes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BillingMeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.user_ui_enabled' => true]);
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/billing/me')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_404_when_user_billing_ui_disabled(): void
    {
        config(['billing.user_ui_enabled' => false]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/me')
            ->assertNotFound();
    }

    public function test_authenticated_user_gets_preview(): void
    {
        $user = User::factory()->create();
        $this->makePlan('legacy_unlimited', 'Legacy Unlimited', [
            'system' => true,
            'limits' => array_fill_keys(BillingCodes::capabilities(), null),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/me')
            ->assertOk()
            ->assertJsonPath('billing.enabled', false)
            ->assertJsonPath('billing.enforce_limits', false)
            ->assertJsonPath('billing.checkout_enabled', false)
            ->assertJsonPath('billing.mode_label', 'Тестовый период')
            ->assertJsonPath('current_plan.code', 'legacy_unlimited')
            ->assertJsonPath('current_plan.name', 'Legacy Unlimited')
            ->assertJsonPath('subscription.status', 'active')
            ->assertJsonStructure([
                'billing' => ['enabled', 'enforce_limits', 'log_only', 'checkout_enabled', 'mode_label'],
                'current_plan' => ['code', 'name', 'description', 'price', 'currency', 'billing_period', 'is_default'],
                'subscription' => ['status', 'current_period_start', 'current_period_end'],
                'usage',
                'public_plans',
            ]);
    }

    public function test_user_sees_only_own_subscription_and_usage(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownPlan = $this->makePlan('own_plan', 'Own Plan', [
            'limits' => [
                BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 10,
                BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 20,
            ],
        ]);
        $this->makePlan('other_plan', 'Other Plan');

        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $ownPlan->id,
            'plan_code' => 'own_plan',
            'status' => 'active',
            'source' => 'manual',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        BillingSubscription::query()->create([
            'user_id' => $otherUser->id,
            'plan_code' => 'other_plan',
            'status' => 'active',
            'source' => 'manual',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        Project::query()->create([
            'user_id' => $user->id,
            'number' => uniqid('BILL-ME-OWN-'),
            'expert_name' => 'Own Expert',
            'address' => 'Own address',
        ]);
        Project::query()->create([
            'user_id' => $otherUser->id,
            'number' => uniqid('BILL-ME-OTHER-'),
            'expert_name' => 'Other Expert',
            'address' => 'Other address',
        ]);

        $this->usage($user, BillingCodes::METRIC_PDF_SMETA_GENERATED, 2);
        $this->usage($otherUser, BillingCodes::METRIC_PDF_SMETA_GENERATED, 99);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/billing/me')->assertOk();

        $this->assertSame('own_plan', $response->json('current_plan.code'));
        $this->assertSame(1, $this->usageItem($response->json('usage'), 'projects.active')['used']);
        $this->assertSame(2, $this->usageItem($response->json('usage'), 'pdf.generated')['used']);
        $this->assertSame(20, $this->usageItem($response->json('usage'), 'pdf.generated')['limit']);
    }

    public function test_billing_disabled_does_not_break_endpoint(): void
    {
        config([
            'billing.enabled' => false,
            'billing.enforce_limits' => false,
            'billing.payments.checkout_ui_enabled' => false,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/me')
            ->assertOk()
            ->assertJsonPath('billing.enabled', false)
            ->assertJsonPath('billing.enforce_limits', false)
            ->assertJsonPath('billing.checkout_enabled', false);
    }

    public function test_hidden_sandbox_and_system_plans_are_not_public(): void
    {
        $user = User::factory()->create();
        $this->makePlan('public_plan', 'Public Plan', [
            'hidden' => false,
            'sandbox' => false,
            'system' => false,
        ]);
        $this->makePlan('hidden_plan', 'Hidden Plan', ['hidden' => true]);
        $this->makePlan('sandbox_plan', 'Sandbox Plan', ['hidden' => false, 'sandbox' => true]);
        $this->makePlan('system_plan', 'System Plan', ['hidden' => false, 'system' => true]);
        $this->makePlan('legacy_unlimited', 'Legacy Unlimited', ['hidden' => false, 'system' => true]);
        $this->makePlan('inactive_plan', 'Inactive Plan', ['hidden' => false], isActive: false);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/billing/me')->assertOk();

        $codes = collect($response->json('public_plans'))->pluck('code')->all();
        $this->assertSame(['public_plan'], $codes);
        $this->assertFalse($response->json('public_plans.0.is_current'));
        $this->assertFalse($response->json('public_plans.0.is_available'));
    }

    public function test_public_plan_payload_contains_user_card_fields(): void
    {
        $user = User::factory()->create();
        $plan = $this->makePlan('sandbox_pro_month', 'Sandbox Pro Month', [
            'hidden' => false,
            'sandbox' => false,
            'system' => false,
            'price_minor' => 99000,
            'billing_period' => 'month',
            'description' => 'Для активной работы с проектами',
            'limits' => [
                BillingCodes::CAP_PROJECTS_MAX_ACTIVE => 30,
                BillingCodes::CAP_PDF_EXPORTS_MONTHLY_LIMIT => 100,
                BillingCodes::CAP_EVIDENCE_RUNS_MONTHLY_LIMIT => 50,
                BillingCodes::CAP_CHROME_CAPTURES_MONTHLY_LIMIT => 300,
                BillingCodes::CAP_STORAGE_MAX_MB => 5120,
            ],
        ]);

        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_code' => 'sandbox_pro_month',
            'status' => 'active',
            'source' => 'manual',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/me')
            ->assertOk()
            ->assertJsonPath('public_plans.0.code', 'sandbox_pro_month')
            ->assertJsonPath('public_plans.0.name', 'Профессиональный')
            ->assertJsonPath('public_plans.0.price', 99000)
            ->assertJsonPath('public_plans.0.price_minor', 99000)
            ->assertJsonPath('public_plans.0.currency', 'RUB')
            ->assertJsonPath('public_plans.0.period', 'month')
            ->assertJsonPath('public_plans.0.is_current', true)
            ->assertJsonPath('public_plans.0.is_available', false)
            ->assertJsonPath('public_plans.0.limits.0.label', 'Активные проекты')
            ->assertJsonPath('public_plans.0.limits.0.name', 'Активные проекты')
            ->assertJsonPath('public_plans.0.limits.2.label', 'Проверки цен')
            ->assertJsonPath('public_plans.0.limits.3.label', 'Скриншоты из расширения')
            ->assertJsonPath('public_plans.0.limits.4.label', 'Хранилище файлов');
    }

    public function test_response_does_not_contain_provider_or_secret_fields(): void
    {
        $user = User::factory()->create();
        $this->makePlan('public_plan', 'Public Plan', [
            'hidden' => false,
            'sandbox' => false,
            'system' => false,
            'secret_key' => 'must-not-leak',
            'provider' => 'yookassa',
            'receipt_settings' => ['vat' => 1],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/billing/me')->assertOk();
        $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);

        foreach (['yookassa', 'provider', 'payment', 'invoice', 'webhook', 'gate_events', 'secret_key', 'receipt'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_endpoint_does_not_create_gate_events_or_enable_checkout_and_enforcement(): void
    {
        config([
            'billing.enabled' => false,
            'billing.enforce_limits' => false,
            'billing.payments.checkout_ui_enabled' => false,
        ]);

        $user = User::factory()->create();
        $before = BillingGateEvent::query()->count();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/me')
            ->assertOk()
            ->assertJsonPath('billing.enforce_limits', false)
            ->assertJsonPath('billing.checkout_enabled', false);

        $this->assertSame($before, BillingGateEvent::query()->count());
        $this->assertFalse((bool) config('billing.enforce_limits'));
        $this->assertFalse((bool) config('billing.payments.checkout_ui_enabled'));
    }

    private function makePlan(string $code, string $name, array $metadata = [], bool $isActive = true): BillingPlan
    {
        $metadata = array_replace_recursive([
            'price_minor' => null,
            'currency' => 'RUB',
            'billing_period' => null,
            'hidden' => true,
            'sandbox' => false,
            'system' => false,
            'description' => "{$name} description",
            'features' => ['Feature A'],
            'limits' => array_fill_keys(BillingCodes::capabilities(), null),
        ], $metadata);

        return BillingPlan::query()->create([
            'code' => $code,
            'name' => $name,
            'is_active' => $isActive,
            'metadata_json' => $metadata,
        ]);
    }

    private function usage(User $user, string $metricCode, int $quantity): void
    {
        UsageEvent::query()->create([
            'owner_type' => 'user',
            'owner_id' => $user->id,
            'user_id' => $user->id,
            'metric_code' => $metricCode,
            'feature_code' => BillingCodes::FEATURE_PDF_SMETA,
            'quantity' => $quantity,
            'unit' => 'count',
            'source' => 'api',
            'occurred_at' => now(),
        ]);
    }

    private function usageItem(array $items, string $code): array
    {
        $item = collect($items)->firstWhere('code', $code);
        $this->assertIsArray($item);

        return $item;
    }
}
