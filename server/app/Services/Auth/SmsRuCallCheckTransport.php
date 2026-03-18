<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsRuCallCheckTransport implements VerificationTransportInterface
{
    public function sendCode(string $phone, string $code): array
    {
        if (config('verification.test_mode')) {
            $checkId = 'test_callcheck_' . time();
            Log::info('[SmsRuCallCheck][TEST] Challenge created for ' . $phone, ['check_id' => $checkId]);

            return [
                'success' => true,
                'provider_message_id' => $checkId,
                'error' => null,
                'meta' => [
                    'call_phone' => '78005008275',
                    'call_phone_pretty' => '+7 (800) 500-82-75',
                ],
            ];
        }

        if (!$this->isAvailable()) {
            return ['success' => false, 'provider_message_id' => null, 'error' => 'SMS.ru CallCheck не настроен'];
        }

        $normalized = preg_replace('/\D+/', '', $phone ?? '');

        try {
            $response = Http::timeout((int) config('verification.sms_ru.timeout', 15))
                ->get('https://sms.ru/callcheck/add', [
                    'api_id' => config('verification.sms_ru.api_id'),
                    'phone' => $normalized,
                    'json' => 1,
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'provider_message_id' => null,
                    'error' => 'SMS.ru CallCheck HTTP ' . $response->status(),
                ];
            }

            $body = $response->json();
            $statusCode = (int) ($body['status_code'] ?? 0);

            if (($body['status'] ?? null) === 'OK' && $statusCode === 100 && !empty($body['check_id'])) {
                return [
                    'success' => true,
                    'provider_message_id' => (string) $body['check_id'],
                    'error' => null,
                    'meta' => [
                        'call_phone' => $body['call_phone'] ?? null,
                        'call_phone_pretty' => $body['call_phone_pretty'] ?? null,
                        'call_phone_html' => $body['call_phone_html'] ?? null,
                    ],
                ];
            }

            return [
                'success' => false,
                'provider_message_id' => null,
                'error' => (string) ($body['status_text'] ?? ('SMS.ru CallCheck error code ' . $statusCode)),
            ];
        } catch (\Throwable $e) {
            Log::warning('[SmsRuCallCheck] Add failed', [
                'phone' => substr((string) $phone, 0, 5) . '***',
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'provider_message_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, confirmed: bool, pending: bool, expired: bool, error: ?string}
     */
    public function getCheckStatus(string $checkId): array
    {
        if (config('verification.test_mode')) {
            $confirmed = (bool) config('verification.sms_ru.test_confirmed', true);
            return [
                'success' => true,
                'confirmed' => $confirmed,
                'pending' => !$confirmed,
                'expired' => false,
                'error' => null,
            ];
        }

        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'confirmed' => false,
                'pending' => false,
                'expired' => false,
                'error' => 'SMS.ru CallCheck не настроен',
            ];
        }

        try {
            $response = Http::timeout((int) config('verification.sms_ru.timeout', 15))
                ->get('https://sms.ru/callcheck/status', [
                    'api_id' => config('verification.sms_ru.api_id'),
                    'check_id' => $checkId,
                    'json' => 1,
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'confirmed' => false,
                    'pending' => false,
                    'expired' => false,
                    'error' => 'SMS.ru CallCheck status HTTP ' . $response->status(),
                ];
            }

            $body = $response->json();
            $statusCode = (int) ($body['status_code'] ?? 0);

            if (($body['status'] ?? null) !== 'OK' || $statusCode !== 100) {
                return [
                    'success' => false,
                    'confirmed' => false,
                    'pending' => false,
                    'expired' => false,
                    'error' => (string) ($body['status_text'] ?? ('SMS.ru CallCheck status error code ' . $statusCode)),
                ];
            }

            $checkStatus = (string) ($body['check_status'] ?? '');

            return [
                'success' => true,
                'confirmed' => $checkStatus === '401',
                'pending' => $checkStatus === '400',
                'expired' => $checkStatus === '402',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[SmsRuCallCheck] Status check failed', [
                'check_id' => $checkId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'confirmed' => false,
                'pending' => false,
                'expired' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function isAvailable(): bool
    {
        return (bool) config('verification.sms_ru.enabled')
            && !empty(config('verification.sms_ru.api_id'));
    }

    public function channelName(): string
    {
        return 'sms_ru_callcheck';
    }
}
