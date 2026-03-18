<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramGatewayTransport implements VerificationTransportInterface
{
    public function sendCode(string $phone, string $code): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'provider_message_id' => null, 'error' => 'Telegram Gateway не настроен'];
        }

        // Dev/test mock mode
        if (config('verification.test_mode')) {
            Log::info('[TelegramGateway][TEST] Code for ' . $phone . ': ' . $code);
            return ['success' => true, 'provider_message_id' => 'test_tg_' . time(), 'error' => null];
        }

        try {
            $response = Http::withToken(config('verification.telegram_gateway.token'))
                ->timeout(config('verification.telegram_gateway.ttl', 10))
                ->post('https://gatewayapi.telegram.org/sendVerificationMessage', [
                    'phone_number' => $phone,
                    'code' => $code,
                    'ttl' => config('verification.telegram_gateway.code_ttl', 300),
                ]);

            if ($response->successful()) {
                $body = $response->json();
                if (!empty($body['ok'])) {
                    return [
                        'success' => true,
                        'provider_message_id' => $body['result']['request_id'] ?? null,
                        'error' => null,
                    ];
                }
                return [
                    'success' => false,
                    'provider_message_id' => null,
                    'error' => $body['error'] ?? 'Telegram Gateway: unknown error',
                ];
            }

            return [
                'success' => false,
                'provider_message_id' => null,
                'error' => 'Telegram Gateway HTTP ' . $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[TelegramGateway] Send failed', [
                'phone' => substr($phone, 0, 5) . '***',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'provider_message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function isAvailable(): bool
    {
        return (bool) config('verification.telegram_gateway.enabled')
            && !empty(config('verification.telegram_gateway.token'));
    }

    public function channelName(): string
    {
        return 'telegram_gateway';
    }
}
