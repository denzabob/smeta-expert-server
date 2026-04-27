<?php

namespace App\Services\Billing\Payments;

class ProviderPayloadSanitizer
{
    private const SENSITIVE_KEYS = [
        'secret',
        'secret_key',
        'authorization',
        'Authorization',
        'password',
        'token',
        'access_token',
        'refresh_token',
        'card_number',
        'pan',
        'cvc',
        'cvv',
        'first6',
    ];

    public function sanitize(array $payload): array
    {
        return $this->sanitizeValue($payload);
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$key] = $this->sanitizeValue($item);
        }

        return $clean;
    }
}
