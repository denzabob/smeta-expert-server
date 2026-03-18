<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SmsRuCallCheckHealthController extends Controller
{
    /**
     * GET /api/auth/phone/callcheck/health
     *
     * Protected diagnostics endpoint for SMS.ru CallCheck integration.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $appUrl = rtrim((string) config('app.url', ''), '/');
        $webhookPath = '/api/auth/phone/callcheck/webhook';

        $smsEnabled = (bool) config('verification.sms_ru.enabled', false);
        $apiId = (string) config('verification.sms_ru.api_id', '');
        $apiIdPresent = $apiId !== '';

        $webhookEnabled = (bool) config('verification.sms_ru.webhook.enabled', true);
        $webhookToken = (string) config('verification.sms_ru.webhook.token', '');
        $webhookTokenPresent = $webhookToken !== '';

        $issues = [];

        if (!$smsEnabled) {
            $issues[] = 'sms_ru_disabled';
        }
        if (!$apiIdPresent) {
            $issues[] = 'sms_ru_api_id_missing';
        }
        if ($webhookEnabled && !$webhookTokenPresent) {
            $issues[] = 'webhook_token_missing';
        }
        if ($appUrl === '') {
            $issues[] = 'app_url_missing';
        }

        $status = empty($issues) ? 'ok' : 'warning';

        $response = [
            'status' => $status,
            'provider' => 'sms_ru_callcheck',
            'config' => [
                'sms_ru_enabled' => $smsEnabled,
                'api_id_present' => $apiIdPresent,
                'timeout_seconds' => (int) config('verification.sms_ru.timeout', 15),
                'webhook_enabled' => $webhookEnabled,
                'webhook_token_present' => $webhookTokenPresent,
            ],
            'webhook' => [
                'path' => $webhookPath,
                'url' => $appUrl !== '' ? $appUrl . $webhookPath : null,
                'secured_url' => ($appUrl !== '' && $webhookTokenPresent)
                    ? $appUrl . $webhookPath . '?token=' . $webhookToken
                    : null,
            ],
            'issues' => $issues,
            'probe' => null,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($request->boolean('probe')) {
            $response['probe'] = $this->runProviderProbe();
        }

        return response()->json($response);
    }

    /**
     * Probe SMS.ru CallCheck API connectivity with a harmless status request.
     *
     * @return array{attempted: bool, success: bool, http_status: ?int, provider_status_code: ?int, provider_status_text: ?string, error: ?string}
     */
    private function runProviderProbe(): array
    {
        $smsEnabled = (bool) config('verification.sms_ru.enabled', false);
        $apiId = (string) config('verification.sms_ru.api_id', '');

        if (!$smsEnabled || $apiId === '') {
            return [
                'attempted' => false,
                'success' => false,
                'http_status' => null,
                'provider_status_code' => null,
                'provider_status_text' => null,
                'error' => 'sms_ru_not_configured',
            ];
        }

        try {
            $response = Http::timeout((int) config('verification.sms_ru.timeout', 15))
                ->get('https://sms.ru/callcheck/status', [
                    'api_id' => $apiId,
                    'check_id' => 'health_probe_' . time(),
                    'json' => 1,
                ]);

            $body = $response->json();

            return [
                'attempted' => true,
                'success' => $response->successful(),
                'http_status' => $response->status(),
                'provider_status_code' => isset($body['status_code']) ? (int) $body['status_code'] : null,
                'provider_status_text' => $body['status_text'] ?? null,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'attempted' => true,
                'success' => false,
                'http_status' => null,
                'provider_status_code' => null,
                'provider_status_text' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
