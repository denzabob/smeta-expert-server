<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthVerificationChallenge;
use App\Models\User;
use App\Services\Auth\AuthMethodProfileService;
use App\Services\Auth\VerificationCodeService;
use App\Services\AuthAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Yandex-only / factor-less bootstrap controller.
 *
 * Allows an authenticated user who has no verified step-up factor to add
 * and verify a phone number. This is the escape hatch for the "Yandex-only
 * bootstrap trap" — the user is already authenticated (auth:sanctum), so
 * we treat the active session as sufficient trust for the narrow operation
 * of ADDING a new recovery factor. This does not grant step-up for any
 * other action.
 *
 * Scope: phone linking only. Once the phone is verified, the normal
 * step-up model (StepUpService) takes over for all future sensitive actions.
 *
 * Route map:
 *   POST /api/security/bootstrap/phone/initiate  → initiatePhoneLink()
 *   POST /api/security/bootstrap/phone/verify    → verifyPhoneLink()
 */
class BootstrapController extends Controller
{
    public function __construct(
        private readonly VerificationCodeService  $verificationCodeService,
        private readonly AuthMethodProfileService $profileService,
        private readonly AuthAuditService         $audit,
    ) {}

    /**
     * POST /api/security/bootstrap/phone/initiate
     *
     * Start a phone-link flow from an authenticated session.
     * No step-up token required — the authenticated session is the trust anchor
     * for this specific, additive operation.
     *
     * Available to any authenticated user who does not yet have a verified phone.
     * Designed primarily for Yandex-only accounts escaping the bootstrap trap.
     */
    public function initiatePhoneLink(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'min:10', 'max:20'],
        ]);

        $user = $request->user();

        if ($this->profileService->hasVerifiedPhone($user)) {
            return response()->json([
                'message' => 'Телефон уже подтверждён. Для смены номера используйте раздел методов входа.',
                'error'   => 'phone_already_verified',
            ], 422);
        }

        $phone = VerificationCodeService::normalizePhone($request->input('phone'));

        // Verify the phone is not in use by another account
        $occupied = User::where('phone', $phone)
            ->where('id', '!=', $user->id)
            ->exists();

        if ($occupied) {
            return response()->json([
                'message' => 'Этот номер уже используется другим аккаунтом.',
                'errors'  => ['phone' => ['Этот номер уже используется.']],
            ], 422);
        }

        $result = $this->verificationCodeService->createChallenge(
            phone: $phone,
            purpose: 'phone_link_verify',
            ip: (string) $request->ip(),
            email: $user->email,
            userId: $user->id,
        );

        if ($result['error'] === 'rate_limited') {
            return response()->json(['message' => 'Слишком много попыток. Подождите немного.'], 429);
        }

        if ($result['error'] === 'delivery_failed') {
            return response()->json(['message' => 'Не удалось отправить код. Попробуйте позже.'], 503);
        }

        $phoneChallenge = $result['challenge'];

        $this->audit->methodPhoneLinkStarted($user->id, $request, [
            'masked_phone' => VerificationCodeService::maskPhone($phone),
        ]);

        return response()->json([
            'challenge_id'        => $phoneChallenge->id,
            'phone_masked'        => VerificationCodeService::maskPhone($phone),
            'channel'             => $result['channel_used'],
            'verification_method' => $result['channel_used'] === 'sms_ru_callcheck' ? 'call' : 'code',
            'call_phone'          => $result['call_phone'],
            'call_phone_pretty'   => $result['call_phone_pretty'],
            'resend_available_at' => $phoneChallenge->resend_available_at?->toIso8601String(),
            'expires_at'          => $phoneChallenge->expires_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/security/bootstrap/phone/verify
     *
     * Verify OTP and link the phone to the account.
     * On success, `phone` and `phone_verified_at` are set on the user.
     * After this, the normal step-up model is available (phone OTP factor).
     */
    public function verifyPhoneLink(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code'         => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $phoneChallenge = AuthVerificationChallenge::where('id', $request->input('challenge_id'))
            ->where('user_id', $user->id)
            ->where('purpose', 'phone_link_verify')
            ->first();

        if (!$phoneChallenge || !$phoneChallenge->isPending()) {
            return response()->json([
                'message' => 'Сессия подтверждения не найдена или истекла.',
            ], 422);
        }

        $result = $this->verificationCodeService->verifyCode(
            $phoneChallenge,
            (string) $request->input('code', ''),
        );

        if (!$result['valid']) {
            if ($result['error'] === 'challenge_expired') {
                return response()->json(['message' => 'Срок действия кода истёк. Запросите новый.'], 410);
            }
            if ($result['error'] === 'too_many_attempts') {
                return response()->json(['message' => 'Слишком много попыток. Запросите новый код.'], 422);
            }
            if ($result['error'] === 'call_not_confirmed') {
                return response()->json([
                    'message' => 'Звонок ещё не подтверждён. Позвоните на указанный номер.',
                    'error'   => 'call_not_confirmed',
                ], 409);
            }
            return response()->json(['message' => 'Неверный код подтверждения.'], 422);
        }

        // Link and verify the phone
        $user->update([
            'phone'             => $phoneChallenge->phone,
            'phone_verified_at' => now(),
        ]);

        $hasYandex = $this->profileService->hasYandexLink($user->fresh());

        $this->audit->methodPhoneVerified($user->id, $request, [
            'masked_phone' => VerificationCodeService::maskPhone($phoneChallenge->phone),
        ]);

        if ($hasYandex) {
            $this->audit->methodYandexBootstrapCompleted($user->id, $request, [
                'masked_phone' => VerificationCodeService::maskPhone($phoneChallenge->phone),
            ]);
        }

        return response()->json([
            'message'        => 'Телефон успешно подтверждён.',
            'phone_verified' => true,
            'phone_masked'   => VerificationCodeService::maskPhone($phoneChallenge->phone),
        ]);
    }
}
