<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingCapabilitiesTest extends TestCase
{
    private array $originalEnv = [];

    public function test_guest_cannot_read_billing_capabilities(): void
    {
        $this->getJson('/api/billing/capabilities')->assertUnauthorized();
    }

    #[DataProvider('modeProvider')]
    public function test_capabilities_and_legacy_aliases_are_derived_from_mode(
        bool $billingEnabled,
        string $mode,
        array $expected,
    ): void {
        $this->setBillingEnv($billingEnabled, $mode);
        $this->refreshApplication();

        $this->assertSame($expected['enabled'], config('billing.enabled'));
        $this->assertSame($mode, config('billing.mode'));
        $this->assertSame($expected['adminUiEnabled'], config('billing.admin_ui_enabled'));
        $this->assertSame($expected['userUiEnabled'], config('billing.user_ui_enabled'));
        $this->assertSame($expected['checkoutEnabled'], config('billing.checkout_enabled'));
        $this->assertSame($expected['paymentsEnabled'], config('billing.payments.enabled'));
        $this->assertSame($expected['checkoutEnabled'], config('billing.payments.checkout_ui_enabled'));
        $this->assertSame($expected['enforcementEnabled'], config('billing.enforcement_enabled'));
        $this->assertSame($expected['usageTrackingEnabled'], config('billing.usage_tracking_enabled'));
        $this->assertSame($expected['usageTrackingEnabled'], config('billing.track_usage'));
        $this->assertSame($expected['enforcementEnabled'], config('billing.enforce_limits'));
        $this->assertSame($expected['logOnly'], config('billing.log_only'));
        $this->assertSame('yookassa', config('billing.payments.default_provider'));
        $this->assertSame('test', config('billing.payments.providers.yookassa.mode'));
        $this->assertSame($expected['paymentsEnabled'], config('billing.payments.providers.yookassa.enabled'));

        $user = new User([
            'name' => 'Billing User',
            'email' => 'billing@example.com',
        ]);
        $user->id = 12345;

        Sanctum::actingAs($user);

        $this->getJson('/api/billing/capabilities')
            ->assertOk()
            ->assertJsonPath('billing.enabled', $expected['enabled'])
            ->assertJsonPath('billing.mode', $mode)
            ->assertJsonPath('billing.adminUiEnabled', $expected['adminUiEnabled'])
            ->assertJsonPath('billing.userUiEnabled', $expected['userUiEnabled'])
            ->assertJsonPath('billing.checkoutEnabled', $expected['checkoutEnabled'])
            ->assertJsonPath('billing.paymentsEnabled', $expected['paymentsEnabled'])
            ->assertJsonPath('billing.enforcementEnabled', $expected['enforcementEnabled'])
            ->assertJsonPath('billing.usageTrackingEnabled', $expected['usageTrackingEnabled'])
            ->assertJsonPath('billing.provider', 'yookassa')
            ->assertJsonPath('billing.providerMode', 'test')
            ->assertJsonPath('billing.defaultPlan', 'legacy_unlimited')
            ->assertJsonPath('billing.failOpen', true);
    }

    public static function modeProvider(): array
    {
        return [
            'off' => [
                false,
                'off',
                [
                    'enabled' => false,
                    'adminUiEnabled' => false,
                    'userUiEnabled' => false,
                    'checkoutEnabled' => false,
                    'paymentsEnabled' => false,
                    'enforcementEnabled' => false,
                    'usageTrackingEnabled' => false,
                    'logOnly' => true,
                ],
            ],
            'admin_only' => [
                true,
                'admin_only',
                [
                    'enabled' => true,
                    'adminUiEnabled' => true,
                    'userUiEnabled' => false,
                    'checkoutEnabled' => false,
                    'paymentsEnabled' => false,
                    'enforcementEnabled' => false,
                    'usageTrackingEnabled' => true,
                    'logOnly' => true,
                ],
            ],
            'visible' => [
                true,
                'visible',
                [
                    'enabled' => true,
                    'adminUiEnabled' => true,
                    'userUiEnabled' => true,
                    'checkoutEnabled' => false,
                    'paymentsEnabled' => false,
                    'enforcementEnabled' => false,
                    'usageTrackingEnabled' => true,
                    'logOnly' => true,
                ],
            ],
            'checkout' => [
                true,
                'checkout',
                [
                    'enabled' => true,
                    'adminUiEnabled' => true,
                    'userUiEnabled' => true,
                    'checkoutEnabled' => true,
                    'paymentsEnabled' => true,
                    'enforcementEnabled' => false,
                    'usageTrackingEnabled' => true,
                    'logOnly' => true,
                ],
            ],
            'enforced' => [
                true,
                'enforced',
                [
                    'enabled' => true,
                    'adminUiEnabled' => true,
                    'userUiEnabled' => true,
                    'checkoutEnabled' => true,
                    'paymentsEnabled' => true,
                    'enforcementEnabled' => true,
                    'usageTrackingEnabled' => true,
                    'logOnly' => false,
                ],
            ],
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }

    private function setBillingEnv(bool $enabled, string $mode): void
    {
        $this->setEnv('BILLING_ENABLED', $enabled ? 'true' : 'false');
        $this->setEnv('BILLING_MODE', $mode);
        $this->setEnv('BILLING_DEFAULT_PLAN', 'legacy_unlimited');
        $this->setEnv('BILLING_FAIL_OPEN', 'true');
        $this->setEnv('BILLING_PROVIDER_DEFAULT', 'yookassa');
        $this->setEnv('BILLING_PROVIDER_YOOKASSA_MODE', 'test');
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $current = getenv($key);
            $this->originalEnv[$key] = $current === false ? null : $current;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
