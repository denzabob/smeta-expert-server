<?php

namespace App\Services\Auth;

use App\Models\AuthVerificationChallenge;
use App\Models\StepUpChallenge;
use App\Models\User;
use App\Notifications\StepUpEmailOtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Universal step-up authentication service.
 *
 * A step-up challenge proves recent ownership of the account without
 * mandating a specific factor.  The allowed factor depends on what
 * auth methods the user actually has (password, phone OTP, or both).
 *
 * Lifecycle:
 *  1. initiate()             → StepUpChallenge (status=pending)
 *  2a. verifyByPassword()    → step_up_token string
 *  2b. requestPhoneOtp()     → phone challenge data (then user enters code)
 *  2c. verifyByPhoneOtp()    → step_up_token string
 *  3. (caller) saves token and returns it to frontend
 *  4. Next sensitive request includes token
 *  5. validateToken()        → StepUpChallenge (for guard checks)
 *  6. consumeToken()         → (token is invalidated after use)
 */
class StepUpService
{
    /** Minutes before the verification window closes. */
    private const CHALLENGE_TTL_MINUTES = 10;

    /** Minutes the step-up token remains valid after successful verification. */
    private const TOKEN_TTL_MINUTES = 15;

    /** Minutes for email OTP challenge TTL. */
    private const EMAIL_OTP_TTL_MINUTES = 10;

    /** Max email OTP send attempts per user per hour. */
    private const EMAIL_OTP_RATE_LIMIT = 5;

    /** Max code-entry attempts for email OTP (stricter than phone OTP). */
    private const EMAIL_OTP_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly AuthMethodProfileService  $profileService,
        private readonly VerificationCodeService   $verificationCodeService,
    ) {}

    // ─── Allowed methods ─────────────────────────────────────────────────────

    /**
     * Determine which step-up factors the user can use.
     *
     * Order matters: password is tried first since it produces a token
     * immediately without a second round-trip. Email OTP is listed before
     * phone OTP because email delivery is reliable in all environments.
     *
     * @return list<'password'|'email_otp'|'phone_otp'>
     */
    public function allowedMethods(User $user): array
    {
        $methods = [];

        if ($this->profileService->hasPassword($user)) {
            $methods[] = 'password';
        }

        if ($this->profileService->hasVerifiedEmail($user)) {
            $methods[] = 'email_otp';
        }

        if ($this->profileService->hasVerifiedPhone($user)) {
            $methods[] = 'phone_otp';
        }

        return $methods;
    }

    /**
     * Return true when the user has at least one usable step-up factor.
     */
    public function canStepUp(User $user): bool
    {
        return !empty($this->allowedMethods($user));
    }

    // ─── Initiate ────────────────────────────────────────────────────────────

    /**
     * Create a new pending step-up challenge.
     *
     * @throws \InvalidArgumentException When scope is invalid.
     * @throws StepUpNotPossibleException When the user has no usable factor.
     */
    public function initiate(User $user, string $scope, string $ip): StepUpChallenge
    {
        if (!in_array($scope, StepUpChallenge::SCOPES, true)) {
            throw new \InvalidArgumentException("Unknown step-up scope: {$scope}");
        }

        $methods = $this->allowedMethods($user);

        if (empty($methods)) {
            throw new StepUpNotPossibleException(
                'У вас нет подходящего метода аутентификации для выполнения этого действия. '
                . 'Добавьте пароль или подтвердите номер телефона.'
            );
        }

        // Cancel any existing pending challenges for the same scope + user
        StepUpChallenge::where('user_id', $user->id)
            ->where('scope', $scope)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        return StepUpChallenge::create([
            'user_id'         => $user->id,
            'scope'           => $scope,
            'allowed_methods' => $methods,
            'status'          => 'pending',
            'expires_at'      => now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
            'ip_address'      => $ip,
        ]);
    }

    // ─── Verify by password ──────────────────────────────────────────────────

    /**
     * Verify the step-up challenge using the user's password.
     * Returns the step-up token on success.
     *
     * @throws StepUpMethodNotAllowedException
     * @throws StepUpChallengeExpiredException
     * @throws StepUpInvalidCredentialsException
     */
    public function verifyByPassword(StepUpChallenge $challenge, string $password): string
    {
        $this->assertChallengeUsable($challenge, 'password');

        $user = $challenge->user;

        if (!$user->password || !Hash::check($password, $user->password)) {
            // Mark challenge as failed if it was the only method
            $methods = $challenge->allowed_methods;
            if (count($methods) === 1 && $methods[0] === 'password') {
                $challenge->update(['status' => 'failed']);
            }

            throw new StepUpInvalidCredentialsException('Неверный пароль.');
        }

        return $this->completeChallenge($challenge, 'password');
    }
    // ─── Request email OTP ────────────────────────────────────────────────────────

    /**
     * Send an OTP code to the user's verified email address for step-up.
     * Returns data needed by the frontend to display the entry form.
     *
     * Rate-limited per user: at most EMAIL_OTP_RATE_LIMIT sends per hour.
     * Each call cancels the previous pending email challenge for this step-up.
     *
     * @return array{email_challenge_id: string, email_masked: string, resend_available_at: string, expires_at: string}
     * @throws StepUpMethodNotAllowedException
     * @throws StepUpChallengeExpiredException
     * @throws \RuntimeException On rate_limited
     */
    public function requestEmailOtp(StepUpChallenge $challenge): array
    {
        $this->assertChallengeUsable($challenge, 'email_otp');

        $user = $challenge->user;

        if (!$this->profileService->hasVerifiedEmail($user)) {
            throw new StepUpMethodNotAllowedException('Email не подтверждён.');
        }

        // Rate limit: max EMAIL_OTP_RATE_LIMIT sends per user per hour
        $rateLimitKey = 'step_up_email_otp_send:' . $user->id;
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::EMAIL_OTP_RATE_LIMIT)) {
            throw new \RuntimeException('rate_limited');
        }
        RateLimiter::hit($rateLimitKey, 3600);

        // Cancel previous pending email OTP challenges for this user
        AuthVerificationChallenge::where('user_id', $user->id)
            ->where('purpose', 'step_up_email')
            ->where('status', 'pending')
            ->update(['status' => 'canceled']);

        // Generate OTP code (respects VERIFICATION_TEST_CODE in dev/test mode)
        $code = $this->generateEmailOtpCode();
        $resendCooldown = config('verification.resend_cooldown_seconds', 60);

        $emailChallenge = AuthVerificationChallenge::create([
            'id'                   => Str::uuid()->toString(),
            'purpose'              => 'step_up_email',
            'phone'                => null,
            'email'                => $user->email,
            'code_hash'            => Hash::make($code),
            'expires_at'           => now()->addMinutes(self::EMAIL_OTP_TTL_MINUTES),
            'attempts_left'        => self::EMAIL_OTP_MAX_ATTEMPTS,
            'resend_available_at'  => now()->addSeconds($resendCooldown),
            'status'               => 'pending',
            'current_channel'      => 'email',
            'channel_attempt_order' => ['email'],
            'user_id'              => $user->id,
            'ip_address'           => $challenge->ip_address,
        ]);

        // Bind to step-up challenge
        $challenge->update(['email_challenge_id' => $emailChallenge->id]);

        // Send email (queued)
        $user->notify(new StepUpEmailOtpNotification($code));

        return [
            'email_challenge_id'  => $emailChallenge->id,
            'email_masked'        => $this->profileService->maskEmail($user->email),
            'resend_available_at' => $emailChallenge->resend_available_at->toIso8601String(),
            'expires_at'          => $emailChallenge->expires_at->toIso8601String(),
        ];
    }

    // ─── Verify by email OTP ──────────────────────────────────────────────────────

    /**
     * Verify the step-up challenge using an email OTP code.
     * Returns the step-up token on success.
     *
     * @throws StepUpMethodNotAllowedException
     * @throws StepUpChallengeExpiredException
     * @throws StepUpInvalidCredentialsException
     */
    public function verifyByEmailOtp(
        StepUpChallenge $challenge,
        string $emailChallengeId,
        string $code
    ): string {
        $this->assertChallengeUsable($challenge, 'email_otp');

        if ($challenge->email_challenge_id !== $emailChallengeId) {
            throw new StepUpInvalidCredentialsException('Сессия подтверждения не совпадает.');
        }

        $emailChallenge = AuthVerificationChallenge::where('id', $emailChallengeId)
            ->where('purpose', 'step_up_email')
            ->first();

        if (!$emailChallenge) {
            throw new StepUpInvalidCredentialsException('Сессия OTP не найдена.');
        }

        $result = $this->verificationCodeService->verifyCode($emailChallenge, $code);

        if (!$result['valid']) {
            if (in_array($result['error'], ['challenge_expired', 'too_many_attempts'], true)) {
                throw new StepUpChallengeExpiredException('Срок действия кода истёк. Запросите новый код.');
            }

            throw new StepUpInvalidCredentialsException('Неверный код подтверждения.');
        }

        return $this->completeChallenge($challenge, 'email_otp');
    }
    // ─── Request phone OTP ───────────────────────────────────────────────────

    /**
     * Send a phone OTP for step-up.  Returns challenge data the frontend
     * needs to display (masked phone, resend timer, etc.).
     *
     * @return array{phone_challenge_id: string, masked_phone: string, resend_available_at: string|null, expires_at: string, channel: string, call_phone: string|null, call_phone_pretty: string|null}
     * @throws StepUpMethodNotAllowedException
     * @throws StepUpChallengeExpiredException
     * @throws \RuntimeException On delivery failure
     */
    public function requestPhoneOtp(StepUpChallenge $challenge): array
    {
        $this->assertChallengeUsable($challenge, 'phone_otp');

        $user = $challenge->user;

        $result = $this->verificationCodeService->createChallenge(
            phone: $user->phone,
            purpose: 'step_up',
            ip: (string) $challenge->ip_address,
            email: $user->email,
            userId: $user->id,
        );

        if ($result['error'] === 'rate_limited') {
            throw new \RuntimeException('rate_limited');
        }

        if ($result['error'] === 'delivery_failed') {
            throw new \RuntimeException('delivery_failed');
        }

        $phoneChallenge = $result['challenge'];

        // Bind the phone challenge to this step-up challenge
        $challenge->update(['phone_challenge_id' => $phoneChallenge->id]);

        return [
            'phone_challenge_id'  => $phoneChallenge->id,
            'masked_phone'        => VerificationCodeService::maskPhone($user->phone),
            'resend_available_at' => $phoneChallenge->resend_available_at?->toIso8601String(),
            'expires_at'          => $phoneChallenge->expires_at->toIso8601String(),
            'channel'             => $result['channel_used'],
            'call_phone'          => $result['call_phone'],
            'call_phone_pretty'   => $result['call_phone_pretty'],
        ];
    }

    // ─── Verify by phone OTP ─────────────────────────────────────────────────

    /**
     * Verify the step-up challenge using a phone OTP code (or call-check).
     * Returns the step-up token on success.
     *
     * @throws StepUpMethodNotAllowedException
     * @throws StepUpChallengeExpiredException
     * @throws StepUpInvalidCredentialsException
     */
    public function verifyByPhoneOtp(StepUpChallenge $challenge, string $phoneChallengeId, string $code): string
    {
        $this->assertChallengeUsable($challenge, 'phone_otp');

        if ($challenge->phone_challenge_id !== $phoneChallengeId) {
            throw new StepUpInvalidCredentialsException('Сессия подтверждения не совпадает.');
        }

        $phoneChallenge = AuthVerificationChallenge::where('id', $phoneChallengeId)
            ->where('purpose', 'step_up')
            ->first();

        if (!$phoneChallenge) {
            throw new StepUpInvalidCredentialsException('Сессия OTP не найдена.');
        }

        $result = $this->verificationCodeService->verifyCode($phoneChallenge, $code);

        if (!$result['valid']) {
            if (in_array($result['error'], ['challenge_expired', 'too_many_attempts'], true)) {
                throw new StepUpChallengeExpiredException('Срок действия кода истёк. Запросите новый код.');
            }

            if ($result['error'] === 'call_not_confirmed') {
                throw new StepUpInvalidCredentialsException('call_not_confirmed');
            }

            throw new StepUpInvalidCredentialsException('Неверный код подтверждения.');
        }

        return $this->completeChallenge($challenge, 'phone_otp');
    }

    // ─── Validate / consume token ─────────────────────────────────────────────

    /**
     * Validate that a step-up token is valid for the given user and scope.
     * Does NOT consume the token.
     *
     * @throws StepUpTokenInvalidException
     */
    public function validateToken(string $token, User $user, string $scope): StepUpChallenge
    {
        $challenge = StepUpChallenge::findValidToken($token, $user->id, $scope);

        if (!$challenge) {
            throw new StepUpTokenInvalidException(
                'Требуется повторная аутентификация. Токен недействителен или истёк.'
            );
        }

        return $challenge;
    }

    /**
     * Consume (invalidate) a step-up token so it cannot be reused.
     */
    public function consumeToken(StepUpChallenge $challenge): void
    {
        $challenge->consume();
    }

    // ─── Internal ────────────────────────────────────────────────────────────

    private function assertChallengeUsable(StepUpChallenge $challenge, string $method): void
    {
        if (!$challenge->isPending()) {
            throw new StepUpChallengeExpiredException('Сессия верификации уже использована или истекла.');
        }

        if ($challenge->isExpiredVerification()) {
            $challenge->markExpired();
            throw new StepUpChallengeExpiredException('Срок действия сессии верификации истёк.');
        }

        if (!in_array($method, $challenge->allowed_methods, true)) {
            throw new StepUpMethodNotAllowedException(
                "Метод '{$method}' не разрешён для этой сессии верификации."
            );
        }
    }

    private function completeChallenge(StepUpChallenge $challenge, string $method): string
    {
        $rawToken = Str::random(64);

        $challenge->update([
            'status'           => 'completed',
            'completed_method' => $method,
            'completed_at'     => now(),
            'token'            => hash('sha256', $rawToken), // store hash only; raw token is returned to client
            'token_expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ]);

        return $rawToken;
    }

    private function generateEmailOtpCode(): string
    {
        $testCode = config('verification.test_code');
        if (!empty($testCode)) {
            return (string) $testCode;
        }
        return str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }
}

// ─── Domain exceptions ───────────────────────────────────────────────────────

class StepUpNotPossibleException extends \RuntimeException {}
class StepUpChallengeExpiredException extends \RuntimeException {}
class StepUpMethodNotAllowedException extends \RuntimeException {}
class StepUpInvalidCredentialsException extends \RuntimeException {}
class StepUpTokenInvalidException extends \RuntimeException {}
