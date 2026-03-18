<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsRuTransport implements VerificationTransportInterface
{
    public function sendCode(string $phone, string $code): array
    {
        if (!$this->isAvailable()) {
            return ['success' => false, 'provider_message_id' => null, 'error' => 'SMS.ru не настроен'];
        }

        // Dev/test mock mode
        if (config('verification.test_mode')) {
            Log::info('[SmsRu][TEST] Code for ' . $phone . ': ' . $code);
            return ['success' => true, 'provider_message_id' => 'test_sms_' . time(), 'error' => null];
        }

        $message = config('verification.sms_ru.message_template', 'Ваш код: {code}');
        $message = str_replace('{code}', $code, $message);

        try {
            $response = Http::timeout(15)
                ->get('https://sms.ru/sms/send', [
                    'api_id' => config('verification.sms_ru.api_id'),
                    'to' => $phone,
                    'msg' => $message,
                    'json' => 1,
                    'from' => config('verification.sms_ru.from'),
                ]);

            if ($response->successful()) {
                $body = $response->json();
                // SMS.ru returns status_code=100 for success
                if (isset($body['status_code']) && $body['status_code'] == 100) {
                    $smsId = null;
                    if (isset($body['sms'][$phone]['sms_id'])) {
                        $smsId = $body['sms'][$phone]['sms_id'];
                    }
                    return ['success' => true, 'provider_message_id' => $smsId, 'error' => null];
                }

                $errorText = $body['status_text'] ?? 'SMS.ru: unknown error';
                return ['success' => false, 'provider_message_id' => null, 'error' => $errorText];
            }

            return [
                'success' => false,
                'provider_message_id' => null,
                'error' => 'SMS.ru HTTP ' . $response->status(),
            ];
        } catch (\Throwable $e) {
            Log::warning('[SmsRu] Send failed', [
                'phone' => substr($phone, 0, 5) . '***',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'provider_message_id' => null, 'error' => $e->getMessage()];
        }
    }

    public function isAvailable(): bool
    {
        return (bool) config('verification.sms_ru.enabled')
            && !empty(config('verification.sms_ru.api_id'));
    }

    public function channelName(): string
    {
        return 'sms_ru';
    }
}
