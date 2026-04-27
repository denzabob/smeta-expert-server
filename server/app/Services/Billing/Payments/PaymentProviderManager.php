<?php

namespace App\Services\Billing\Payments;

use App\Services\Billing\Payments\Contracts\PaymentProviderInterface;
use App\Services\Billing\Payments\Providers\YooKassaPaymentProvider;
use InvalidArgumentException;
use RuntimeException;

class PaymentProviderManager
{
    public function driver(?string $code = null): PaymentProviderInterface
    {
        $code ??= (string) config('billing.payments.default_provider', 'yookassa');

        return match ($code) {
            'yookassa' => app(YooKassaPaymentProvider::class),
            default => throw new InvalidArgumentException("Unknown billing payment provider [$code]."),
        };
    }

    public function defaultDriver(): PaymentProviderInterface
    {
        return $this->driver();
    }

    public function isEnabled(string $code): bool
    {
        return (bool) config("billing.payments.providers.$code.enabled", false);
    }

    public function assertEnabled(string $code): void
    {
        if (! (bool) config('billing.payments.enabled', false)) {
            throw new RuntimeException('Billing payments are disabled.');
        }

        if (! $this->isEnabled($code)) {
            throw new RuntimeException("Billing payment provider [$code] is disabled.");
        }
    }
}
