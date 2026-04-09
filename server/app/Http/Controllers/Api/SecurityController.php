<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthMethodProfileService;
use App\Services\Auth\StepUpService;
use App\Services\Auth\StepUpNotPossibleException;
use App\Services\Auth\StepUpChallengeExpiredException;
use App\Services\Auth\StepUpMethodNotAllowedException;
use App\Services\Auth\StepUpInvalidCredentialsException;
use App\Services\Auth\StepUpTokenInvalidException;
use App\Services\Auth\VerificationCodeService;
use App\Services\AuthAuditService;
use App\Models\StepUpChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Security-sensitive account actions.
 *
 * All routes require auth:sanctum.
 *
 * Route map:
 *   GET  /api/security/auth-status              → authStatus()
 *   POST /api/security/step-up/initiate          → stepUpInitiate()
 *   POST /api/security/step-up/verify-password   → stepUpVerifyPassword()
 *   POST /api/security/step-up/request-phone-otp → stepUpRequestPhoneOtp()
 *   POST /api/security/step-up/verify-phone-otp  → stepUpVerifyPhoneOtp()
 *   POST /api/security/step-up/request-email-otp → stepUpRequestEmailOtp()  [Block 6A]
 *   POST /api/security/step-up/verify-email-otp  → stepUpVerifyEmailOtp()   [Block 6A]
 *   POST /api/security/password/set              → setPassword()
 */
class SecurityController extends Controller
{
    public function __construct(
        private readonly AuthMethodProfileService $profileService,
        private readonly StepUpService            $stepUpService,
        private readonly AuthAuditService         $audit,
    ) {}

    // ─── Auth-method status ──────────────────────────────────────────────────

    /**
     * GET /api/security/auth-status
     *
     * Returns the canonical auth-method profile for the authenticated user.
     * Safe to call on any page load — used to drive completion banners/cards.
     */
    public function authStatus(Request $request): JsonResponse
    {
        return response()->json($this->profileService->profile($request->user()));
    }

    // ─── Step-up: Initiate ──────────────────────────────────────────────────

    /**
     * POST /api/security/step-up/initiate
     *
     * Start a step-up challenge for a sensitive action.
     * Returns allowed methods and challenge metadata (no secrets).
     */
    public function stepUpInitiate(Request $request): JsonResponse
    {
        $request->validate([
            'scope' => ['required', 'string', 'in:' . implode(',', StepUpChallenge::SCOPES)],
        ]);

        $user = $request->user();

        try {
            $challenge = $this->stepUpService->initiate($user, $request->input('scope'), (string) $request->ip());
        } catch (StepUpNotPossibleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error'   => 'no_valid_factor',
                'recommended_actions' => $this->profileService->profile($user)['recommended_actions'],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => 'Неверный scope.'], 422);
        }

        $this->audit->stepUpChallengeStarted($user->id, $request, [
            'scope'           => $challenge->scope,
            'allowed_methods' => $challenge->allowed_methods,
        ]);

        return response()->json([
            'challenge_id'    => $challenge->id,
            'scope'           => $challenge->scope,
            'allowed_methods' => $challenge->allowed_methods,
            'expires_at'      => $challenge->expires_at->toIso8601String(),
            'phone_masked'    => $this->profileService->hasVerifiedPhone($user)
                ? VerificationCodeService::maskPhone($user->phone)
                : null,
            'email_masked'    => $this->profileService->hasVerifiedEmail($user)
                ? $this->profileService->maskEmail($user->email)
                : null,
        ]);
    }

    // ─── Step-up: Verify by password ────────────────────────────────────────

    /**
     * POST /api/security/step-up/verify-password
     *
     * Verify a pending step-up challenge using a password.
     * Returns a step_up_token valid for 15 minutes and bound to the scope.
     */
    public function stepUpVerifyPassword(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'password'     => ['required', 'string'],
        ]);

        $user      = $request->user();
        $challenge = $this->lookupPendingChallenge($request->input('challenge_id'), $user->id);

        if (!$challenge) {
            return response()->json(['message' => 'Сессия верификации не найдена или истекла.'], 422);
        }

        try {
            $token = $this->stepUpService->verifyByPassword($challenge, $request->input('password'));
        } catch (StepUpMethodNotAllowedException) {
            return response()->json(['message' => 'Метод недоступен для этой сессии.'], 422);
        } catch (StepUpChallengeExpiredException) {
            return response()->json(['message' => 'Срок действия сессии истёк. Начните снова.'], 410);
        } catch (StepUpInvalidCredentialsException) {
            $this->audit->stepUpChallengeFailed($user->id, $request, [
                'scope'  => $challenge->scope,
                'method' => 'password',
                'reason' => 'invalid_password',
            ]);
            return response()->json(['message' => 'Неверный пароль.'], 401);
        }

        $this->audit->stepUpChallengeCompleted($user->id, $request, [
            'scope'  => $challenge->scope,
            'method' => 'password',
        ]);

        return response()->json([
            'step_up_token'    => $token,
            'scope'            => $challenge->scope,
            'expires_at'       => $challenge->fresh()->token_expires_at->toIso8601String(),
        ]);
    }

    // ─── Step-up: Request phone OTP ─────────────────────────────────────────

    /**
     * POST /api/security/step-up/request-phone-otp
     *
     * Send an OTP to the user's verified phone as a step-up factor.
     */
    public function stepUpRequestPhoneOtp(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
        ]);

        $user      = $request->user();
        $challenge = $this->lookupPendingChallenge($request->input('challenge_id'), $user->id);

        if (!$challenge) {
            return response()->json(['message' => 'Сессия верификации не найдена или истекла.'], 422);
        }

        try {
            $data = $this->stepUpService->requestPhoneOtp($challenge);
        } catch (StepUpMethodNotAllowedException) {
            return response()->json(['message' => 'Метод недоступен для этой сессии.'], 422);
        } catch (StepUpChallengeExpiredException) {
            return response()->json(['message' => 'Срок действия сессии истёк. Начните снова.'], 410);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'rate_limited') {
                return response()->json(['message' => 'Слишком много попыток. Подождите немного.'], 429);
            }

            return response()->json(['message' => 'Не удалось отправить код. Попробуйте позже.'], 503);
        }

        return response()->json($data);
    }

    // ─── Step-up: Verify phone OTP ───────────────────────────────────────────

    /**
     * POST /api/security/step-up/verify-phone-otp
     *
     * Verify the OTP code as a step-up factor.
     * Returns a step_up_token valid for 15 minutes.
     */
    public function stepUpVerifyPhoneOtp(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id'       => ['required', 'uuid'],
            'phone_challenge_id' => ['required', 'uuid'],
            'code'               => ['nullable', 'string'],
        ]);

        $user      = $request->user();
        $challenge = $this->lookupPendingChallenge($request->input('challenge_id'), $user->id);

        if (!$challenge) {
            return response()->json(['message' => 'Сессия верификации не найдена или истекла.'], 422);
        }

        try {
            $token = $this->stepUpService->verifyByPhoneOtp(
                $challenge,
                $request->input('phone_challenge_id'),
                (string) $request->input('code', ''),
            );
        } catch (StepUpMethodNotAllowedException) {
            return response()->json(['message' => 'Метод недоступен для этой сессии.'], 422);
        } catch (StepUpChallengeExpiredException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        } catch (StepUpInvalidCredentialsException $e) {
            $reason = $e->getMessage();

            $this->audit->stepUpChallengeFailed($user->id, $request, [
                'scope'  => $challenge->scope,
                'method' => 'phone_otp',
                'reason' => $reason,
            ]);

            if ($reason === 'call_not_confirmed') {
                return response()->json([
                    'message' => 'Звонок ещё не подтверждён. Позвоните на указанный номер и повторите проверку.',
                    'error'   => 'call_not_confirmed',
                ], 409);
            }

            return response()->json(['message' => $reason], 422);
        }

        $this->audit->stepUpChallengeCompleted($user->id, $request, [
            'scope'  => $challenge->scope,
            'method' => 'phone_otp',
        ]);

        return response()->json([
            'step_up_token' => $token,
            'scope'         => $challenge->scope,
            'expires_at'    => $challenge->fresh()->token_expires_at->toIso8601String(),
        ]);
    }

    // ─── Step-up: Request email OTP (Block 6A) ──────────────────────────────

    /**
     * POST /api/security/step-up/request-email-otp
     *
     * Send an OTP to the user's verified email address as a step-up factor.
     * Only works when the user has a verified email.
     */
    public function stepUpRequestEmailOtp(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
        ]);

        $user      = $request->user();
        $challenge = $this->lookupPendingChallenge($request->input('challenge_id'), $user->id);

        if (!$challenge) {
            return response()->json(['message' => 'Сессия верификации не найдена или истекла.'], 422);
        }

        try {
            $data = $this->stepUpService->requestEmailOtp($challenge);
        } catch (StepUpMethodNotAllowedException) {
            return response()->json(['message' => 'Метод недоступен для этой сессии. Подтвердите email для использования этого способа.'], 422);
        } catch (StepUpChallengeExpiredException) {
            return response()->json(['message' => 'Срок действия сессии истёк. Начните снова.'], 410);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'rate_limited') {
                return response()->json(['message' => 'Слишком много попыток. Подождите немного перед повторной отправкой.'], 429);
            }
            return response()->json(['message' => 'Не удалось отправить код. Попробуйте позже.'], 503);
        }

        $this->audit->stepUpEmailOtpSent($user->id, $request, [
            'scope' => $challenge->scope,
        ]);

        return response()->json($data);
    }

    // ─── Step-up: Verify email OTP (Block 6A) ───────────────────────────────

    /**
     * POST /api/security/step-up/verify-email-otp
     *
     * Verify the OTP code received by email as a step-up factor.
     * Returns a step_up_token valid for 15 minutes.
     */
    public function stepUpVerifyEmailOtp(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id'       => ['required', 'uuid'],
            'email_challenge_id' => ['required', 'uuid'],
            'code'               => ['required', 'string', 'size:6'],
        ]);

        $user      = $request->user();
        $challenge = $this->lookupPendingChallenge($request->input('challenge_id'), $user->id);

        if (!$challenge) {
            return response()->json(['message' => 'Сессия верификации не найдена или истекла.'], 422);
        }

        try {
            $token = $this->stepUpService->verifyByEmailOtp(
                $challenge,
                $request->input('email_challenge_id'),
                $request->input('code'),
            );
        } catch (StepUpMethodNotAllowedException) {
            return response()->json(['message' => 'Метод недоступен для этой сессии.'], 422);
        } catch (StepUpChallengeExpiredException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        } catch (StepUpInvalidCredentialsException $e) {
            $this->audit->stepUpEmailOtpFailed($user->id, $request, [
                'scope'  => $challenge->scope,
                'reason' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->stepUpEmailOtpVerified($user->id, $request, [
            'scope' => $challenge->scope,
        ]);

        return response()->json([
            'step_up_token' => $token,
            'scope'         => $challenge->scope,
            'expires_at'    => $challenge->fresh()->token_expires_at->toIso8601String(),
        ]);
    }

    // ─── Set password (passwordless users) ──────────────────────────────────

    /**
     * POST /api/security/password/set
     *
     * Set a password for a user who currently has no password.
     * Requires a valid step-up token with scope=set_password.
     * Users who already have a password must use the change-password flow.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $request->validate([
            'step_up_token' => ['required', 'string'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if ($this->profileService->hasPassword($user)) {
            return response()->json([
                'message' => 'У вас уже установлен пароль. Используйте форму смены пароля.',
                'error'   => 'password_already_set',
            ], 422);
        }

        try {
            $challenge = $this->stepUpService->validateToken(
                $request->input('step_up_token'),
                $user,
                'set_password',
            );
        } catch (StepUpTokenInvalidException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'step_up_required'], 401);
        }

        $user->update(['password' => Hash::make($request->input('password'))]);

        $this->stepUpService->consumeToken($challenge);

        $this->audit->methodPasswordSet($user->id, $request);

        return response()->json([
            'message'      => 'Пароль успешно установлен.',
            'has_password' => true,
        ]);
    }

    // ─── Internal helpers ────────────────────────────────────────────────────

    /**
     * Look up a pending step-up challenge owned by the given user.
     * Returns null when not found, expired, or already used.
     */
    private function lookupPendingChallenge(string $challengeId, int $userId): ?StepUpChallenge
    {
        return StepUpChallenge::where('id', $challengeId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();
    }
}
