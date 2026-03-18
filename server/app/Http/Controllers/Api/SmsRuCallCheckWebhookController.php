<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SmsRuCallCheckWebhookController extends Controller
{
    public function __construct(private readonly VerificationCodeService $verificationService)
    {
    }

    public function __invoke(Request $request): Response
    {
        if (!config('verification.sms_ru.webhook.enabled', true)) {
            return response('disabled', 404);
        }

        $expectedToken = (string) config('verification.sms_ru.webhook.token', '');
        if ($expectedToken !== '') {
            $providedToken = (string) ($request->input('token') ?? $request->header('X-Webhook-Token', ''));
            if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                return response('forbidden', 403);
            }
        }

        $checkId = trim((string) $request->input('check_id', $request->input('id', '')));
        $checkStatus = trim((string) $request->input('check_status', $request->input('status', '')));

        if ($checkId === '' || $checkStatus === '') {
            return response('bad_request', 422);
        }

        $result = $this->verificationService->processCallCheckWebhook($checkId, $checkStatus, $request->all());

        if (!$result['success']) {
            Log::warning('[SmsRuCallCheckWebhook] Failed to process webhook', [
                'check_id' => $checkId,
                'check_status' => $checkStatus,
                'error' => $result['error'],
            ]);
        }

        // SMS providers usually expect fast plain-text ack.
        return response('OK', 200);
    }
}
