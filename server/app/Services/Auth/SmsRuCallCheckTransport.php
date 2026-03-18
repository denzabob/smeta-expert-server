<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsRuCallCheckTransport implements VerificationTransportInterface
{
    public function sendCode(string $phone, string $code): array
    {
        if (config('verification.test_mode')) {
            [$testCallPhone, $testCallPhonePretty] = $this->resolveTestCallPhonePair();
            $checkId = 'test_callcheck_' . time();
            Log::info('[SmsRuCallCheck][TEST] Challenge created for ' . $phone, ['check_id' => $checkId]);

            return [
                'success' => true,
                'provider_message_id' => $checkId,
                'error' => null,
                'meta' => [
                    'call_phone' => $testCallPhone,
                    'call_phone_pretty' => $testCallPhonePretty,
                    'provider_payload' => [
                        'status' => 'OK',
                        'status_code' => 100,
                        'check_id' => $checkId,
                        'check_status' => config('verification.sms_ru.test_confirmed', true) ? '401' : '400',
                    ],
                ],
            ];
        }

        if (!$this->isAvailable()) {
            return ['success' => false, 'provider_message_id' => null, 'error' => 'SMS.ru CallCheck не настроен'];
        }

        $normalized = preg_replace('/\D+/', '', $phone ?? '');

        $officialResult = $this->sendViaOfficialLibrary($normalized);
        if (is_array($officialResult)) {
            if (($officialResult['success'] ?? false) === true) {
                return $officialResult;
            }

            Log::warning('[SmsRuCallCheck] Official library add failed, fallback to HTTP', [
                'error' => $officialResult['error'] ?? 'unknown_error',
            ]);
        }

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
                        'provider_payload' => $body,
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
                'provider_payload' => [
                    'status' => 'OK',
                    'status_code' => 100,
                    'check_id' => $checkId,
                    'check_status' => $confirmed ? '401' : '400',
                ],
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

        $officialStatus = $this->getStatusViaOfficialLibrary($checkId);
        if (is_array($officialStatus)) {
            if (($officialStatus['success'] ?? false) === true) {
                return $officialStatus;
            }

            Log::warning('[SmsRuCallCheck] Official library status failed, fallback to HTTP', [
                'check_id' => $checkId,
                'error' => $officialStatus['error'] ?? 'unknown_error',
            ]);
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
                'provider_payload' => $body,
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
        if (config('verification.test_mode')) {
            return true;
        }

        return (bool) config('verification.sms_ru.enabled')
            && !empty(config('verification.sms_ru.api_id'));
    }

    public function channelName(): string
    {
        return 'sms_ru_callcheck';
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function resolveTestCallPhonePair(): array
    {
        $rawDigits = preg_replace('/\D+/', '', (string) config('verification.sms_ru.test_call_phone', '74995555555'));
        if ($rawDigits === '') {
            $rawDigits = '74995555555';
        }

        if (strlen($rawDigits) === 10) {
            $rawDigits = '7' . $rawDigits;
        }

        if (strlen($rawDigits) === 11 && $rawDigits[0] === '8') {
            $rawDigits[0] = '7';
        }

        $pretty = trim((string) config('verification.sms_ru.test_call_phone_pretty', '+7 (499) 555-5555'));
        if ($pretty === '') {
            $pretty = '+7 (499) 555-5555';
        }

        return [$rawDigits, $pretty];
    }

    /**
     * @return array{success: bool, provider_message_id: ?string, error: ?string, meta?: array<string,mixed>}|null
     */
    protected function sendViaOfficialLibrary(string $normalizedPhone): ?array
    {
        if (!config('verification.sms_ru.official_library.enabled', false)) {
            return null;
        }

        $client = $this->resolveOfficialClient();
        if (!$client) {
            return null;
        }

        $methodNames = ['callcheck_add', 'callcheckAdd', 'callCheckAdd'];
        if (!$this->clientSupportsAnyMethod($client, $methodNames)) {
            return null;
        }

        $response = $this->invokeOfficialMethod(
            $client,
            $methodNames,
            [
                [['phone' => $normalizedPhone, 'json' => 1]],
                [['phone' => $normalizedPhone]],
                [$normalizedPhone],
            ]
        );

        $body = $this->normalizeProviderBody($response);
        if (!$body) {
            return null;
        }

        $statusCode = (int) ($body['status_code'] ?? 0);
        $status = strtoupper((string) ($body['status'] ?? ''));
        $checkId = trim((string) ($body['check_id'] ?? $body['id'] ?? ''));

        if (($status === 'OK' || $statusCode === 100) && $checkId !== '') {
            return [
                'success' => true,
                'provider_message_id' => $checkId,
                'error' => null,
                'meta' => [
                    'call_phone' => $body['call_phone'] ?? null,
                    'call_phone_pretty' => $body['call_phone_pretty'] ?? null,
                    'provider_payload' => $body,
                ],
            ];
        }

        return [
            'success' => false,
            'provider_message_id' => null,
            'error' => (string) ($body['status_text'] ?? ('SMS.ru official library callcheck_add error code ' . $statusCode)),
        ];
    }

    /**
     * @return array{success: bool, confirmed: bool, pending: bool, expired: bool, error: ?string, provider_payload?: array<string,mixed>}|null
     */
    protected function getStatusViaOfficialLibrary(string $checkId): ?array
    {
        if (!config('verification.sms_ru.official_library.enabled', false)) {
            return null;
        }

        $client = $this->resolveOfficialClient();
        if (!$client) {
            return null;
        }

        $methodNames = ['callcheck_status', 'callcheckStatus', 'callCheckStatus'];
        if (!$this->clientSupportsAnyMethod($client, $methodNames)) {
            return null;
        }

        $response = $this->invokeOfficialMethod(
            $client,
            $methodNames,
            [
                [['check_id' => $checkId, 'json' => 1]],
                [['check_id' => $checkId]],
                [$checkId],
            ]
        );

        $body = $this->normalizeProviderBody($response);
        if (!$body) {
            return null;
        }

        $statusCode = (int) ($body['status_code'] ?? 0);
        $status = strtoupper((string) ($body['status'] ?? ''));
        if ($status !== 'OK' && $statusCode !== 100) {
            return [
                'success' => false,
                'confirmed' => false,
                'pending' => false,
                'expired' => false,
                'error' => (string) ($body['status_text'] ?? ('SMS.ru official library callcheck_status error code ' . $statusCode)),
                'provider_payload' => $body,
            ];
        }

        $checkStatus = (string) ($body['check_status'] ?? $body['status'] ?? '');

        return [
            'success' => true,
            'confirmed' => $checkStatus === '401' || strtolower($checkStatus) === 'confirmed',
            'pending' => $checkStatus === '400' || strtolower($checkStatus) === 'pending',
            'expired' => $checkStatus === '402' || strtolower($checkStatus) === 'expired',
            'error' => null,
            'provider_payload' => $body,
        ];
    }

    protected function resolveOfficialClient(): ?object
    {
        $path = (string) config('verification.sms_ru.official_library.path', '');
        if ($path === '' || !is_file($path)) {
            Log::warning('[SmsRuCallCheck] Official library path not found', ['path' => $path]);
            return null;
        }

        $className = (string) config('verification.sms_ru.official_library.class', 'SMSRU');

        try {
            require_once $path;
            if (!class_exists($className)) {
                Log::warning('[SmsRuCallCheck] Official library class not found', ['class' => $className, 'path' => $path]);
                return null;
            }

            $apiId = (string) config('verification.sms_ru.api_id', '');
            return new $className($apiId);
        } catch (\Throwable $e) {
            Log::warning('[SmsRuCallCheck] Failed to bootstrap official library', [
                'path' => $path,
                'class' => $className,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param list<string> $methodNames
     * @param list<array<int,mixed>> $argumentSets
     */
    protected function invokeOfficialMethod(object $client, array $methodNames, array $argumentSets): mixed
    {
        foreach ($methodNames as $method) {
            if (!method_exists($client, $method)) {
                continue;
            }

            foreach ($argumentSets as $args) {
                try {
                    return $client->{$method}(...$args);
                } catch (\Throwable $e) {
                    Log::debug('[SmsRuCallCheck] Official method invocation failed', [
                        'method' => $method,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function normalizeProviderBody(mixed $response): ?array
    {
        if (is_array($response)) {
            return $response;
        }

        if (is_object($response)) {
            return (array) $response;
        }

        return null;
    }

    /**
     * @param list<string> $methodNames
     */
    protected function clientSupportsAnyMethod(object $client, array $methodNames): bool
    {
        foreach ($methodNames as $methodName) {
            if (method_exists($client, $methodName)) {
                return true;
            }
        }

        return false;
    }
}
