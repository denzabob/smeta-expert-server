<?php

namespace App\Services\Auth;

use App\Models\AuthVerificationChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VerificationCodeService
{
    /** @var VerificationTransportInterface[] */
    protected array $transports = [];

    protected SmsRuCallCheckTransport $callCheckTransport;

    public function __construct()
    {
        // Build transport chain: Telegram Gateway first, SMS.ru CallCheck fallback
        $tg = new TelegramGatewayTransport();
        $this->callCheckTransport = new SmsRuCallCheckTransport();

        if ($tg->isAvailable()) {
            $this->transports[] = $tg;
        }
        if ($this->callCheckTransport->isAvailable()) {
            $this->transports[] = $this->callCheckTransport;
        }
    }

    /**
     * Normalize phone to E.164 format.
     */
    public static function normalizePhone(string $phone): string
    {
        // Strip everything except digits and leading +
        $cleaned = preg_replace('/[^\d+]/', '', $phone);

        // Handle Russian numbers: 8XXXXXXXXXX -> +7XXXXXXXXXX
        if (preg_match('/^8(\d{10})$/', $cleaned, $m)) {
            $cleaned = '+7' . $m[1];
        }

        // Ensure leading +
        if (!str_starts_with($cleaned, '+')) {
            // Assume Russian number if 11 digits starting with 7
            if (preg_match('/^7\d{10}$/', $cleaned)) {
                $cleaned = '+' . $cleaned;
            } elseif (preg_match('/^\d{10}$/', $cleaned)) {
                // 10 digits → assume Russian mobile without prefix
                $cleaned = '+7' . $cleaned;
            } else {
                $cleaned = '+' . $cleaned;
            }
        }

        return $cleaned;
    }

    /**
     * Create and send a verification challenge.
     *
    * @return array{challenge: ?AuthVerificationChallenge, channel_used: ?string, error: ?string, call_phone: ?string, call_phone_pretty: ?string}
     */
    public function createChallenge(
        string $phone,
        string $purpose,
        string $ip,
        ?string $email = null,
        ?int $userId = null
    ): array {
        $phone = self::normalizePhone($phone);

        // Rate limiting: max challenges per phone+IP per hour
        $maxPerPhoneIp = config('verification.rate_limits.per_phone_ip_hour', 5);
        if (AuthVerificationChallenge::recentCount($phone, $ip) >= $maxPerPhoneIp) {
            return [
                'challenge' => null,
                'channel_used' => null,
                'error' => 'rate_limited',
                'call_phone' => null,
                'call_phone_pretty' => null,
            ];
        }

        // Rate limiting: max challenges per IP per hour
        $maxPerIp = config('verification.rate_limits.per_ip_hour', 20);
        if (AuthVerificationChallenge::recentCountByIp($ip) >= $maxPerIp) {
            return [
                'challenge' => null,
                'channel_used' => null,
                'error' => 'rate_limited',
                'call_phone' => null,
                'call_phone_pretty' => null,
            ];
        }

        // Cancel previous pending challenges for same phone+purpose
        AuthVerificationChallenge::where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('status', 'pending')
            ->update(['status' => 'canceled']);

        // Generate code
        $code = $this->generateCode();
        $ttl = config('verification.code_ttl_minutes', 5);
        $resendCooldown = config('verification.resend_cooldown_seconds', 60);

        $channelOrder = array_map(fn($t) => $t->channelName(), $this->transports);

        $challenge = AuthVerificationChallenge::create([
            'id' => Str::uuid()->toString(),
            'purpose' => $purpose,
            'phone' => $phone,
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($ttl),
            'attempts_left' => config('verification.max_attempts', 5),
            'resend_available_at' => now()->addSeconds($resendCooldown),
            'status' => 'pending',
            'channel_attempt_order' => $channelOrder,
            'user_id' => $userId,
            'ip_address' => $ip,
        ]);

        // Try to deliver via transport chain
        $deliveryResult = $this->deliverCode($phone, $code, $challenge);

        return [
            'challenge' => $challenge->fresh(),
            'channel_used' => $challenge->current_channel,
            'error' => $deliveryResult['success'] ? null : 'delivery_failed',
            'call_phone' => $deliveryResult['meta']['call_phone'] ?? null,
            'call_phone_pretty' => $deliveryResult['meta']['call_phone_pretty'] ?? null,
        ];
    }

    /**
     * Resend code for an existing challenge.
     *
    * @return array{channel_used: ?string, error: ?string, next_retry_at: ?string, call_phone: ?string, call_phone_pretty: ?string}
     */
    public function resendCode(AuthVerificationChallenge $challenge): array
    {
        if (!$challenge->isPending()) {
            return [
                'channel_used' => null,
                'error' => 'challenge_not_pending',
                'next_retry_at' => null,
                'call_phone' => null,
                'call_phone_pretty' => null,
            ];
        }

        if (!$challenge->canResend()) {
            return [
                'channel_used' => null,
                'error' => 'resend_cooldown',
                'next_retry_at' => $challenge->resend_available_at->toIso8601String(),
                'call_phone' => null,
                'call_phone_pretty' => null,
            ];
        }

        // Generate new code, reset attempts
        $code = $this->generateCode();
        $resendCooldown = config('verification.resend_cooldown_seconds', 60);

        $challenge->update([
            'code_hash' => Hash::make($code),
            'attempts_left' => config('verification.max_attempts', 5),
            'resend_available_at' => now()->addSeconds($resendCooldown),
        ]);

        $deliveryResult = $this->deliverCode($challenge->phone, $code, $challenge);

        return [
            'channel_used' => $challenge->current_channel,
            'error' => $deliveryResult['success'] ? null : 'delivery_failed',
            'next_retry_at' => $challenge->resend_available_at->toIso8601String(),
            'call_phone' => $deliveryResult['meta']['call_phone'] ?? null,
            'call_phone_pretty' => $deliveryResult['meta']['call_phone_pretty'] ?? null,
        ];
    }

    /**
     * Verify the code for a challenge.
     *
     * @return array{valid: bool, error: ?string}
     */
    public function verifyCode(AuthVerificationChallenge $challenge, string $code): array
    {
        if ($challenge->current_channel === 'sms_ru_callcheck') {
            return $this->verifyCallCheck($challenge);
        }

        if (!$challenge->isPending()) {
            return ['valid' => false, 'error' => 'challenge_expired'];
        }

        if (!$challenge->hasAttemptsLeft()) {
            return ['valid' => false, 'error' => 'too_many_attempts'];
        }

        if (!$challenge->verifyCode($code)) {
            $challenge->recordFailedAttempt();
            return [
                'valid' => false,
                'error' => 'invalid_code',
            ];
        }

        $challenge->markVerified();
        return ['valid' => true, 'error' => null];
    }

    /**
     * Verify call-based challenge through SMS.ru CallCheck status API.
     *
     * @return array{valid: bool, error: ?string}
     */
    protected function verifyCallCheck(AuthVerificationChallenge $challenge): array
    {
        if ($challenge->status === 'verified') {
            return ['valid' => true, 'error' => null];
        }

        if (!$challenge->isPending()) {
            return ['valid' => false, 'error' => 'challenge_expired'];
        }

        $checkId = (string) ($challenge->provider_message_id ?? '');
        if ($checkId === '') {
            return ['valid' => false, 'error' => 'provider_error'];
        }

        $status = $this->callCheckTransport->getCheckStatus($checkId);

        if (!$status['success']) {
            return ['valid' => false, 'error' => 'provider_error'];
        }

        if ($status['confirmed']) {
            $challenge->markVerified();
            return ['valid' => true, 'error' => null];
        }

        if ($status['expired']) {
            $challenge->markExpired();
            return ['valid' => false, 'error' => 'challenge_expired'];
        }

        return ['valid' => false, 'error' => 'call_not_confirmed'];
    }

    /**
     * Process CallCheck webhook update and sync challenge status.
     *
     * @return array{success: bool, processed: bool, error: ?string}
     */
    public function processCallCheckWebhook(string $checkId, string $checkStatus): array
    {
        $challenge = AuthVerificationChallenge::where('current_channel', 'sms_ru_callcheck')
            ->where('provider_message_id', $checkId)
            ->latest('created_at')
            ->first();

        if (!$challenge) {
            // Idempotent OK: challenge may already be cleaned up.
            return ['success' => true, 'processed' => false, 'error' => null];
        }

        if ($checkStatus === '401') {
            if ($challenge->status !== 'verified') {
                $challenge->markVerified();
            }

            return ['success' => true, 'processed' => true, 'error' => null];
        }

        if ($checkStatus === '402') {
            if ($challenge->status === 'pending') {
                $challenge->markExpired();
            }

            return ['success' => true, 'processed' => true, 'error' => null];
        }

        if ($checkStatus === '400') {
            // Not confirmed yet, keep pending unless already expired by TTL.
            if ($challenge->isExpired() && $challenge->status === 'pending') {
                $challenge->markExpired();
            }

            return ['success' => true, 'processed' => true, 'error' => null];
        }

        $challenge->update([
            'last_error' => 'sms_ru_callcheck webhook unknown status: ' . $checkStatus,
        ]);

        return ['success' => false, 'processed' => true, 'error' => 'unknown_status'];
    }

    /**
     * Deliver code through transport chain with fallback.
     */
    protected function deliverCode(string $phone, string $code, AuthVerificationChallenge $challenge): array
    {
        if (empty($this->transports)) {
            // No transports configured — test mode fallback
            if (config('verification.test_mode')) {
                $challenge->update([
                    'current_channel' => 'test',
                    'provider_message_id' => 'test_' . time(),
                ]);
                return ['success' => true, 'meta' => null];
            }

            $challenge->update([
                'status' => 'failed',
                'last_error' => 'No delivery transports configured',
            ]);
            return ['success' => false, 'meta' => null];
        }

        foreach ($this->transports as $transport) {
            $result = $transport->sendCode($phone, $code);

            if ($result['success']) {
                $challenge->update([
                    'current_channel' => $transport->channelName(),
                    'provider_message_id' => $result['provider_message_id'],
                    'last_error' => null,
                ]);
                return [
                    'success' => true,
                    'meta' => is_array($result['meta'] ?? null) ? $result['meta'] : null,
                ];
            }

            // Log failure, try next transport
            $challenge->update([
                'last_error' => $transport->channelName() . ': ' . ($result['error'] ?? 'unknown'),
            ]);
        }

        // All transports failed
        $challenge->update(['status' => 'failed']);
        return ['success' => false, 'meta' => null];
    }

    /**
     * Generate a secure 6-digit numeric code.
     */
    protected function generateCode(): string
    {
        if (config('verification.test_mode') && config('verification.test_code')) {
            return (string) config('verification.test_code');
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Mask phone for safe public display. +7999***1234
     */
    public static function maskPhone(string $phone): string
    {
        $len = mb_strlen($phone);
        if ($len <= 6) {
            return $phone;
        }
        return mb_substr($phone, 0, 4) . str_repeat('*', $len - 8) . mb_substr($phone, -4);
    }
}
