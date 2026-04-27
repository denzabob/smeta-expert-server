<?php

namespace Tests\Unit\Billing\Payments;

use App\Services\Billing\Payments\PaymentProviderManager;
use App\Services\Billing\Payments\Providers\YooKassaPaymentProvider;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PaymentProviderManagerTest extends TestCase
{
    public function test_returns_yookassa_driver(): void
    {
        $driver = app(PaymentProviderManager::class)->driver('yookassa');

        $this->assertInstanceOf(YooKassaPaymentProvider::class, $driver);
        $this->assertSame('yookassa', $driver->code());
    }

    public function test_unknown_provider_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PaymentProviderManager::class)->driver('unknown');
    }

    public function test_disabled_provider_is_not_enabled(): void
    {
        config()->set('billing.payments.enabled', true);
        config()->set('billing.payments.providers.yookassa.enabled', false);

        $manager = app(PaymentProviderManager::class);

        $this->assertFalse($manager->isEnabled('yookassa'));

        $this->expectException(RuntimeException::class);
        $manager->assertEnabled('yookassa');
    }
}
