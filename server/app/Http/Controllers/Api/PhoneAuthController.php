<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthVerificationChallenge;
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
     * POST /api/auth/phone/request-code
     *
     * Request a verification code for phone login or signup.
     * Anti-enumeration: always returns same shape regardless of user existence.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20'],
        ]);

        $phone = VerificationCodeService::normalizePhone($request->input('phone'));

        // Anti-enumeration: proceed regardless of user existence
        $existingUser = User::where('phone', $phone)->first();

        $result = $this->verificationService->createChallenge(
            $phone,
            'phone_auth',
            $request->ip(),
            $existingUser?->email,
            $existingUser?->id
        );

        if ($result['error'] === 'rate_limited') {
            return response()->json([
                'message' => 'Слишком много попыток. Подождите немного.',
            ], 429);
        }

        if ($result['error'] === 'delivery_failed') {
            return response()->json([
                'message' => 'Не удалось отправить код. Попробуйте позже.',
            ], 503);
        }

        $challenge = $result['challenge'];

        return response()->json([
            'challenge_id' => $challenge->id,
            'channel' => $result['channel_used'],
            'verification_method' => $result['channel_used'] === 'sms_ru_callcheck' ? 'call' : 'code',
            'call_phone' => $result['call_phone'],
            'call_phone_pretty' => $result['call_phone_pretty'],
            'phone_masked' => VerificationCodeService::maskPhone($phone),
            'resend_available_at' => $challenge->resend_available_at?->toIso8601String(),
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/auth/phone/resend-code
     */
    public function resendCode(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
        ]);

        $challenge = AuthVerificationChallenge::find($request->input('challenge_id'));

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
                'message' => 'Не удалось отправить код.',
            ], 503);
        }

        return response()->json([
            'channel' => $result['channel_used'],
            'verification_method' => $result['channel_used'] === 'sms_ru_callcheck' ? 'call' : 'code',
            'call_phone' => $result['call_phone'],
            'call_phone_pretty' => $result['call_phone_pretty'],
            'resend_available_at' => $result['next_retry_at'],
        ]);
    }

    /**
     * POST /api/auth/phone/verify-code
     *
     * Verify the code and either log in or begin onboarding.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['nullable', 'string', 'size:6'],
        ]);

        $challenge = AuthVerificationChallenge::find($request->input('challenge_id'));

        if (!$challenge) {
            return response()->json([
                'message' => 'Сессия подтверждения не найдена.',
            ], 422);
        }

        $isVerifiedCallCheck = $challenge->current_channel === 'sms_ru_callcheck'
            && $challenge->status === 'verified';

        // Expired or exhausted → 410 Gone
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
            if (in_array($result['error'], ['challenge_expired', 'too_many_attempts'])) {
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

            // Invalid code
            $challenge->refresh();
            return response()->json([
                'message' => 'Неверный код подтверждения.',
                'attempts_left' => $challenge->attempts_left,
            ], 422);
        }

        // Code is valid — determine flow
        $phone = $challenge->phone;
        $user = User::where('phone', $phone)->first();

        if ($user) {
            return $this->loginUser($user, $request, 'phone');
        }

        // New user → create pre-user with verified phone
        $user = User::create([
            'name' => '',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'auth_status' => 'active',
            'last_login_channel' => 'phone',
        ]);

        $challenge->update(['user_id' => $user->id]);

        return $this->loginUser($user, $request, 'phone', true);
    }

    /**
     * POST /api/register/complete
     *
     * Complete onboarding for a phone-verified user.
     * Protected route — user is already authenticated via verify-code.
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

        // Check email uniqueness (exclude current user)
        $emailTaken = User::where('email', $request->input('email'))
            ->where('id', '!=', $user->id)
            ->exists();

        if ($emailTaken) {
            return response()->json([
                'message' => 'Этот email уже используется другим аккаунтом.',
                'errors' => ['email' => ['Этот email уже используется.']],
            ], 422);
        }

        $user->update([
            'full_name' => $request->input('full_name'),
            'name' => $request->input('full_name'),
            'email' => $request->input('email'),
            'activity_profile' => $request->input('activity_profile'),
            'registration_completed_at' => now(),
        ]);

        // Create default settings
        if (!$user->settings) {
            UserSettings::createForUser($user);
        }

        return response()->json([
            'user' => $user->fresh(),
            'registration_complete' => true,
        ]);
    }

    /**
     * Log in user with single-session enforcement.
     */
    protected function loginUser(User $user, Request $request, string $channel, bool $forceOnboarding = false): JsonResponse
    {
        Auth::login($user);
        $request->session()->regenerate();

        // Single-session enforcement: delete all other sessions
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

        $responseData = $user->toArray();
        $responseData['status'] = $needsCompletion ? 'needs_onboarding' : 'authenticated';
        $responseData['need_profile_completion'] = $needsCompletion;
        $responseData['pin_enabled'] = (bool) $user->pin_enabled;

        // Check trusted device
        $deviceId = $request->cookie('tdid');
        $hasTrustedDevice = false;
        if ($deviceId) {
            $device = \App\Models\TrustedDevice::findActiveByDeviceId($deviceId);
            if ($device && $device->user_id === $user->id) {
                $hasTrustedDevice = true;
                $device->update(['last_used_at' => now(), 'ip_last' => $request->ip()]);
            }
        }
        $responseData['has_trusted_device'] = $hasTrustedDevice;
        $responseData['should_offer_pin_setup'] = $user->pin_enabled && !$hasTrustedDevice;
        $responseData['should_offer_pin_enable'] = !$user->pin_enabled && !$needsCompletion;

        return response()->json($responseData);
    }
}
