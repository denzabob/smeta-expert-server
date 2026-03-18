<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthVerificationChallenge;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\Auth\LoginMethodService;
use App\Services\Auth\VerificationCodeService;
use App\Services\Auth\YandexAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthMethodController extends Controller
{
    protected LoginMethodService $loginMethodService;
    protected VerificationCodeService $verificationCodeService;
    protected YandexAuthService $yandexAuthService;

    public function __construct(
        LoginMethodService $loginMethodService,
        VerificationCodeService $verificationCodeService,
        YandexAuthService $yandexAuthService
    ) {
        $this->loginMethodService = $loginMethodService;
        $this->verificationCodeService = $verificationCodeService;
        $this->yandexAuthService = $yandexAuthService;
    }

    /**
     * GET /api/auth/methods
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $supportedProviders = array_map(function (array $meta) use ($user) {
            $linkedAccount = $user->socialAccounts()
                ->where('provider', $meta['provider'])
                ->where('is_active', true)
                ->first();

            $canDisconnect = $linkedAccount
                ? $this->loginMethodService->canUnlinkProvider($user, $meta['provider'])
                : false;

            return [
                'provider' => $meta['provider'],
                'label' => $meta['label'],
                'configured' => $meta['configured'],
                'linked' => $linkedAccount !== null,
                'connection_status' => $linkedAccount ? 'connected' : 'not_connected',
                'can_connect' => $meta['configured'] && $linkedAccount === null,
                'can_disconnect' => $canDisconnect,
                'linked_account' => $linkedAccount ? [
                    'provider_user_id' => $linkedAccount->provider_user_id,
                    'provider_username' => $linkedAccount->provider_username,
                    'provider_email' => $linkedAccount->provider_email,
                    'last_used_at' => $linkedAccount->last_used_at?->toIso8601String(),
                ] : null,
            ];
        }, $this->loginMethodService->supportedProviders());

        return response()->json([
            'password' => [
                'enabled' => $this->loginMethodService->hasPasswordMethod($user),
            ],
            'phone' => [
                'value' => $user->phone,
                'masked' => $user->phone ? VerificationCodeService::maskPhone($user->phone) : null,
                'verified' => $user->phone_verified_at !== null,
            ],
            'email' => [
                'value' => $user->email,
                'verified' => $user->email_verified_at !== null,
            ],
            'linked_providers' => $this->loginMethodService->linkedProvidersPayload($user),
            'supported_providers' => $supportedProviders,
            'login_methods_count' => $this->loginMethodService->countLoginMethods($user),
        ]);
    }

    /**
     * GET /api/auth/methods/providers/{provider}/redirect
     */
    public function providerRedirect(Request $request, string $provider): JsonResponse
    {
        $provider = Str::lower($provider);
        $user = $request->user();

        if (!$this->loginMethodService->isProviderSupported($provider)) {
            return response()->json(['message' => 'Провайдер не поддерживается.'], 404);
        }

        if (!$this->loginMethodService->isProviderConfigured($provider)) {
            return response()->json(['message' => 'Провайдер временно недоступен.'], 503);
        }

        if ($provider !== 'yandex') {
            return response()->json(['message' => 'Провайдер пока не реализован.'], 422);
        }

        $state = Str::random(40);
        $request->session()->put('yandex_oauth_state', $state);
        $request->session()->put('yandex_oauth_context', [
            'state' => $state,
            'intent' => 'link',
            'provider' => 'yandex',
            'user_id' => $user->id,
        ]);

        return response()->json([
            'redirect_url' => $this->yandexAuthService->getRedirectUrl($state),
        ]);
    }

    /**
     * POST /api/auth/methods/providers/{provider}/unlink
     */
    public function unlinkProvider(Request $request, string $provider): JsonResponse
    {
        $provider = Str::lower($provider);
        $user = $request->user();

        if (!$this->loginMethodService->isProviderSupported($provider)) {
            return response()->json(['message' => 'Провайдер не поддерживается.'], 404);
        }

        $account = $user->socialAccounts()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['message' => 'Связанный аккаунт не найден.'], 404);
        }

        if (!$this->loginMethodService->canUnlinkProvider($user, $provider)) {
            return response()->json([
                'message' => 'Нельзя отвязать последний способ входа. Сначала добавьте другой метод.',
            ], 422);
        }

        $account->update([
            'is_active' => false,
            'unlinked_at' => now(),
        ]);

        Log::info('[YandexAuth] provider unlinked', [
            'provider' => $provider,
            'provider_user_id' => $account->provider_user_id,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Аккаунт успешно отвязан.',
            'login_methods_count' => $this->loginMethodService->countLoginMethods($user->fresh()),
        ]);
    }

    /**
     * POST /api/auth/methods/phone/request-change
     */
    public function requestPhoneChange(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20'],
            'current_password' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $passwordError = $this->validateSensitiveActionPassword($user, (string) $request->input('current_password', ''));
        if ($passwordError) {
            return $passwordError;
        }

        $phone = VerificationCodeService::normalizePhone($request->input('phone'));

        if ($user->phone === $phone) {
            return response()->json([
                'message' => 'Этот номер уже указан в вашем аккаунте.',
            ], 422);
        }

        $occupied = User::where('phone', $phone)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($occupied) {
            return response()->json([
                'message' => 'Этот номер уже используется другим аккаунтом.',
                'errors' => ['phone' => ['Этот номер уже используется.']],
            ], 422);
        }

        $result = $this->verificationCodeService->createChallenge(
            $phone,
            'phone_change',
            (string) $request->ip(),
            $user->email,
            $user->id
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
     * POST /api/auth/methods/phone/resend-change
     */
    public function resendPhoneChange(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
        ]);

        $challenge = AuthVerificationChallenge::where('id', $request->input('challenge_id'))
            ->where('user_id', $request->user()->id)
            ->where('purpose', 'phone_change')
            ->first();

        if (!$challenge || !$challenge->isPending()) {
            return response()->json([
                'message' => 'Сессия подтверждения не найдена или истекла.',
            ], 422);
        }

        $result = $this->verificationCodeService->resendCode($challenge);

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
     * POST /api/auth/methods/phone/confirm-change
     */
    public function confirmPhoneChange(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['nullable', 'string', 'size:6'],
        ]);

        $user = $request->user();

        $challenge = AuthVerificationChallenge::where('id', $request->input('challenge_id'))
            ->where('user_id', $user->id)
            ->where('purpose', 'phone_change')
            ->first();

        if (!$challenge) {
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

        $result = $this->verificationCodeService->verifyCode($challenge, (string) $request->input('code', ''));

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

        $newPhone = (string) $challenge->phone;

        $occupied = User::where('phone', $newPhone)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($occupied) {
            return response()->json([
                'message' => 'Этот номер уже используется другим аккаунтом.',
                'errors' => ['phone' => ['Этот номер уже используется.']],
            ], 422);
        }

        $user->update([
            'phone' => $newPhone,
            'phone_verified_at' => now(),
        ]);

        return response()->json([
            'message' => 'Номер телефона успешно обновлён.',
            'phone' => $newPhone,
            'phone_masked' => VerificationCodeService::maskPhone($newPhone),
        ]);
    }

    /**
     * POST /api/auth/methods/email/change
     */
    public function changeEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'current_password' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $passwordError = $this->validateSensitiveActionPassword($user, (string) $request->input('current_password', ''));
        if ($passwordError) {
            return $passwordError;
        }

        $email = mb_strtolower(trim((string) $request->input('email')));

        if ($user->email && mb_strtolower($user->email) === $email) {
            return response()->json([
                'message' => 'Этот email уже указан в вашем аккаунте.',
            ], 422);
        }

        $taken = User::whereRaw('LOWER(email) = ?', [$email])
            ->where('id', '!=', $user->id)
            ->exists();

        if ($taken) {
            return response()->json([
                'message' => 'Этот email уже используется другим аккаунтом.',
                'errors' => ['email' => ['Этот email уже используется.']],
            ], 422);
        }

        $user->update([
            'email' => $email,
            'email_verified_at' => null,
        ]);

        $user->notify(new VerifyEmailNotification());

        return response()->json([
            'message' => 'Email обновлён. Мы отправили письмо для подтверждения нового адреса.',
            'email' => $email,
            'email_verified' => false,
        ]);
    }

    protected function validateSensitiveActionPassword(User $user, string $currentPassword): ?JsonResponse
    {
        if (!$user->password) {
            return null;
        }

        if ($currentPassword === '') {
            return response()->json([
                'message' => 'Для этого действия требуется текущий пароль.',
            ], 422);
        }

        if (!Hash::check($currentPassword, $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль неверен.',
            ], 401);
        }

        return null;
    }
}
