<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthVerificationChallenge;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSettings;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PhoneAuthController extends Controller
{
    protected VerificationCodeService $verificationService;

    public function __construct(VerificationCodeService $verificationService)
    {
        $this->verificationService = $verificationService;
    }

    /**
     * Unified phone-first call flow entrypoint.
     * POST /api/auth/phone/call/request
     */
    public function requestCall(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20'],
        ]);

        $phone = VerificationCodeService::normalizePhone((string) $request->input('phone'));
        $existingUser = User::where('phone', $phone)->first();

        $result = $this->verificationService->createChallenge(
            $phone,
            'phone_auth',
            (string) $request->ip(),
            $existingUser?->email,
            $existingUser?->id,
            true
        );

        if ($result['error'] === 'rate_limited') {
            return response()->json([
                'message' => 'Слишком много попыток. Подождите немного.',
            ], 429);
        }

        if ($result['error'] === 'delivery_failed' || !$result['challenge']) {
            return response()->json([
                'message' => 'Не удалось запросить номер для звонка. Попробуйте позже.',
            ], 503);
        }

        /** @var AuthVerificationChallenge $challenge */
        $challenge = $result['challenge'];

        return response()->json([
            'verification_id' => $challenge->id,
            'challenge_id' => $challenge->id,
            'status' => 'pending',
            'phone_masked' => VerificationCodeService::maskPhone($phone),
            'call_phone' => $challenge->call_phone,
            'call_phone_pretty' => $challenge->call_phone_pretty,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'ttl_seconds' => max(0, now()->diffInSeconds($challenge->expires_at, false)),
        ]);
    }

    /**
     * Poll call challenge status and finalize auth when verified.
     * GET|POST /api/auth/phone/call/status
     */
    public function callStatus(Request $request): JsonResponse
    {
        $request->validate([
            'verification_id' => ['nullable', 'uuid'],
            'challenge_id' => ['nullable', 'uuid'],
        ]);

        $challengeId = (string) ($request->input('verification_id') ?: $request->input('challenge_id'));
        if ($challengeId === '') {
            return response()->json([
                'message' => 'Не указан идентификатор подтверждения.',
            ], 422);
        }

        $challenge = AuthVerificationChallenge::where('id', $challengeId)
            ->where('purpose', 'phone_auth')
            ->first();

        if (!$challenge) {
            return response()->json([
                'message' => 'Сессия подтверждения не найдена.',
            ], 404);
        }

        if ($challenge->status === 'pending' && $challenge->isExpired()) {
            $challenge->markExpired();
            $challenge->refresh();
        }

        if ($challenge->status === 'pending') {
            $verification = $this->verificationService->verifyCode($challenge, '');

            if ($verification['valid']) {
                return $this->finalizeCallChallengeAndRespond($challenge, $request);
            }

            if ($verification['error'] === 'provider_error') {
                return response()->json([
                    'verification_id' => $challenge->id,
                    'status' => 'failed',
                    'expires_at' => $challenge->expires_at->toIso8601String(),
                    'ttl_seconds' => max(0, now()->diffInSeconds($challenge->expires_at, false)),
                    'message' => 'Не удалось проверить статус звонка. Попробуйте снова.',
                ], 503);
            }

            if ($verification['error'] === 'challenge_expired') {
                $challenge->refresh();
                return response()->json([
                    'verification_id' => $challenge->id,
                    'status' => 'expired',
                    'expires_at' => $challenge->expires_at->toIso8601String(),
                    'ttl_seconds' => 0,
                    'message' => 'Время ожидания звонка истекло. Запросите новый номер.',
                ], 410);
            }

            return response()->json([
                'verification_id' => $challenge->id,
                'status' => 'pending',
                'call_phone' => $challenge->call_phone,
                'call_phone_pretty' => $challenge->call_phone_pretty,
                'expires_at' => $challenge->expires_at->toIso8601String(),
                'ttl_seconds' => max(0, now()->diffInSeconds($challenge->expires_at, false)),
                'message' => 'Ожидаем звонок.',
            ]);
        }

        if ($challenge->status === 'verified') {
            return $this->finalizeCallChallengeAndRespond($challenge, $request);
        }

        if ($challenge->status === 'expired') {
            return response()->json([
                'verification_id' => $challenge->id,
                'status' => 'expired',
                'expires_at' => $challenge->expires_at->toIso8601String(),
                'ttl_seconds' => 0,
                'message' => 'Время ожидания звонка истекло. Запросите новый номер.',
            ], 410);
        }

        return response()->json([
            'verification_id' => $challenge->id,
            'status' => 'failed',
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'ttl_seconds' => max(0, now()->diffInSeconds($challenge->expires_at, false)),
            'message' => 'Подтверждение не выполнено. Запросите новый номер.',
        ], 422);
    }

    /**
     * Backward-compatible endpoint.
     * POST /api/auth/phone/request-code
     */
    public function requestCode(Request $request): JsonResponse
    {
        $response = $this->requestCall($request);
        $payload = $response->getData(true);

        if ($response->status() >= 400) {
            return $response;
        }

        return response()->json([
            'challenge_id' => $payload['challenge_id'],
            'channel' => 'sms_ru_callcheck',
            'verification_method' => 'call',
            'call_phone' => $payload['call_phone'] ?? null,
            'call_phone_pretty' => $payload['call_phone_pretty'] ?? null,
            'phone_masked' => $payload['phone_masked'] ?? null,
            'resend_available_at' => now()->toIso8601String(),
            'expires_at' => $payload['expires_at'],
        ], 200);
    }

    /**
     * Backward-compatible endpoint.
     * POST /api/auth/phone/resend-code
     */
    public function resendCode(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
        ]);

        $challenge = AuthVerificationChallenge::where('id', (string) $request->input('challenge_id'))
            ->where('purpose', 'phone_auth')
            ->first();

        if (!$challenge || !$challenge->isPending()) {
            return response()->json([
                'message' => 'Сессия подтверждения не найдена или истекла.',
            ], 422);
        }

        $result = $this->verificationService->resendCode($challenge);

        if ($result['error'] === 'resend_cooldown') {
            return response()->json([
                'message' => 'Повторная отправка ещё недоступна.',
                'resend_available_at' => $result['next_retry_at'],
            ], 422);
        }

        if ($result['error']) {
            return response()->json([
                'message' => 'Не удалось запросить новый номер.',
            ], 503);
        }

        $challenge->refresh();

        return response()->json([
            'channel' => $result['channel_used'] ?? $challenge->current_channel,
            'verification_method' => 'call',
            'call_phone' => $challenge->call_phone,
            'call_phone_pretty' => $challenge->call_phone_pretty,
            'resend_available_at' => $result['next_retry_at'],
        ]);
    }

    /**
     * Backward-compatible endpoint.
     * POST /api/auth/phone/verify-code
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['nullable', 'string', 'size:6'],
        ]);

        $challenge = AuthVerificationChallenge::find((string) $request->input('challenge_id'));

        if (!$challenge || $challenge->purpose !== 'phone_auth') {
            return response()->json([
                'message' => 'Сессия подтверждения не найдена.',
            ], 422);
        }

        $isVerifiedCallCheck = $challenge->current_channel === 'sms_ru_callcheck'
            && $challenge->status === 'verified';

        if (!$isVerifiedCallCheck && $challenge->isExpired()) {
            return response()->json([
                'message' => 'Срок действия кода истёк. Запросите новый.',
            ], 410);
        }

        if (!$isVerifiedCallCheck && !$challenge->hasAttemptsLeft()) {
            return response()->json([
                'message' => 'Превышено количество попыток. Запросите новый код.',
            ], 410);
        }

        $isCallCheck = $challenge->current_channel === 'sms_ru_callcheck';
        if (!$isCallCheck && !$request->filled('code')) {
            return response()->json([
                'message' => 'Введите код подтверждения.',
            ], 422);
        }

        $result = $this->verificationService->verifyCode($challenge, (string) $request->input('code', ''));

        if (!$result['valid']) {
            if (in_array($result['error'], ['challenge_expired', 'too_many_attempts'], true)) {
                return response()->json([
                    'message' => 'Срок действия кода истёк. Запросите новый.',
                ], 410);
            }

            if ($result['error'] === 'call_not_confirmed') {
                return response()->json([
                    'message' => 'Звонок ещё не подтверждён. Позвоните на указанный номер и повторите проверку.',
                ], 409);
            }

            if ($result['error'] === 'provider_error') {
                return response()->json([
                    'message' => 'Не удалось проверить статус звонка. Попробуйте снова.',
                ], 503);
            }

            $challenge->refresh();
            return response()->json([
                'message' => 'Неверный код подтверждения.',
                'attempts_left' => $challenge->attempts_left,
            ], 422);
        }

        [$user] = $this->resolveOrCreatePhoneUser($challenge);

        return $this->loginUser($user, $request, 'phone');
    }

    /**
     * POST /api/register/complete
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'activity_profile' => ['required', 'string', 'max:500'],
            'accept_terms' => ['required', 'accepted'],
            'accept_privacy' => ['required', 'accepted'],
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Необходима авторизация.'], 401);
        }

        if ($user->registration_completed_at) {
            return response()->json(['message' => 'Регистрация уже завершена.'], 422);
        }

        $emailTaken = User::where('email', (string) $request->input('email'))
            ->where('id', '!=', $user->id)
            ->exists();

        if ($emailTaken) {
            return response()->json([
                'message' => 'Этот email уже используется другим аккаунтом.',
                'errors' => ['email' => ['Этот email уже используется.']],
            ], 422);
        }

        $user->update([
            'full_name' => (string) $request->input('full_name'),
            'name' => (string) $request->input('full_name'),
            'email' => (string) $request->input('email'),
            'activity_profile' => (string) $request->input('activity_profile'),
            'registration_completed_at' => now(),
        ]);

        if (!$user->settings) {
            UserSettings::createForUser($user);
        }

        return response()->json([
            'user' => $user->fresh(),
            'registration_complete' => true,
        ]);
    }

    protected function finalizeCallChallengeAndRespond(AuthVerificationChallenge $challenge, Request $request): JsonResponse
    {
        [$user] = $this->resolveOrCreatePhoneUser($challenge);
        $authPayload = $this->buildAuthenticatedPayload($user, $request, 'phone');

        return response()->json([
            'verification_id' => $challenge->id,
            'status' => 'verified',
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'ttl_seconds' => max(0, now()->diffInSeconds($challenge->expires_at, false)),
            'auth' => $authPayload,
            'message' => 'Звонок подтвержден.',
        ]);
    }

    /**
     * @return array{0: User, 1: bool}
     */
    protected function resolveOrCreatePhoneUser(AuthVerificationChallenge $challenge): array
    {
        $phone = (string) $challenge->phone;

        $user = $challenge->user;
        if (!$user) {
            $user = User::where('phone', $phone)->first();
        }

        $isNew = false;
        if (!$user) {
            $user = User::firstOrCreate(
                ['phone' => $phone],
                [
                    'name' => '',
                    'phone_verified_at' => now(),
                    'auth_status' => 'active',
                    'last_login_channel' => 'phone',
                ]
            );
            $isNew = $user->wasRecentlyCreated;
        }

        if (!$user->phone_verified_at) {
            $user->update([
                'phone_verified_at' => now(),
            ]);
        }

        if ((int) ($challenge->user_id ?? 0) !== (int) $user->id) {
            $challenge->update(['user_id' => $user->id]);
        }

        return [$user->fresh(), $isNew];
    }

    protected function loginUser(User $user, Request $request, string $channel, bool $forceOnboarding = false): JsonResponse
    {
        return response()->json($this->buildAuthenticatedPayload($user, $request, $channel, $forceOnboarding));
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildAuthenticatedPayload(User $user, Request $request, string $channel, bool $forceOnboarding = false): array
    {
        Auth::login($user);
        $request->session()->regenerate();

        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $user->update([
            'current_session_id' => $currentSessionId,
            'last_login_channel' => $channel,
        ]);

        $needsCompletion = !$user->registration_completed_at || $forceOnboarding;

        $responseData = $user->fresh()->toArray();
        $responseData['status'] = $needsCompletion ? 'needs_onboarding' : 'authenticated';
        $responseData['need_profile_completion'] = $needsCompletion;
        $responseData['pin_enabled'] = (bool) $user->pin_enabled;

        $deviceId = $request->cookie('tdid');
        $hasTrustedDevice = false;
        if ($deviceId) {
            $device = TrustedDevice::findActiveByDeviceId($deviceId);
            if ($device && $device->user_id === $user->id) {
                $hasTrustedDevice = true;
                $device->update([
                    'last_used_at' => now(),
                    'ip_last' => $request->ip(),
                ]);
            }
        }

        $responseData['has_trusted_device'] = $hasTrustedDevice;
        $responseData['should_offer_pin_setup'] = $user->pin_enabled && !$hasTrustedDevice;
        $responseData['should_offer_pin_enable'] = !$user->pin_enabled && !$needsCompletion;

        return $responseData;
    }
}
