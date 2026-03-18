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

        $jobs = [];

        $checkId = trim((string) $request->input('check_id', $request->input('id', '')));
        $checkStatus = trim((string) $request->input('check_status', $request->input('status', '')));
        if ($checkId !== '' && $checkStatus !== '') {
            $jobs[] = [
                'id' => $checkId,
                'status' => $checkStatus,
                'payload' => $request->all(),
            ];
        }

        foreach ($this->parseOfficialCallbackEntries($request->input('data')) as $entry) {
            $jobs[] = [
                'id' => $entry['id'],
                'status' => $entry['status'],
                'payload' => [
                    'line_type' => $entry['line_type'],
                    'raw_entry' => $entry['raw_entry'],
                    'raw_request' => $request->all(),
                ],
            ];
        }

        if (empty($jobs)) {
            return response('bad_request', 422);
        }

        foreach ($jobs as $job) {
            $result = $this->verificationService->processCallCheckWebhook(
                $job['id'],
                $job['status'],
                $job['payload']
            );

            if (!$result['success']) {
                Log::warning('[SmsRuCallCheckWebhook] Failed to process webhook', [
                    'check_id' => $job['id'],
                    'check_status' => $job['status'],
                    'error' => $result['error'],
                ]);
            }
        }

        // Official SMS.ru callback format expects plain "100" ACK.
        if (is_array($request->input('data'))) {
            return response('100', 200);
        }

        return response('OK', 200);
    }

    /**
     * @param mixed $data
     * @return array<int,array{id:string,status:string,line_type:string,raw_entry:string}>
     */
    protected function parseOfficialCallbackEntries(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $rawEntry) {
            if (!is_string($rawEntry) || trim($rawEntry) === '') {
                continue;
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($rawEntry));
            if (!$lines || count($lines) < 3) {
                continue;
            }

            $lineType = trim((string) $lines[0]);
            $id = trim((string) $lines[1]);
            $status = trim((string) $lines[2]);

            if ($id === '' || $status === '') {
                continue;
            }

            $entries[] = [
                'id' => $id,
                'status' => $status,
                'line_type' => $lineType,
                'raw_entry' => $rawEntry,
            ];
        }

        return $entries;
    }
}
