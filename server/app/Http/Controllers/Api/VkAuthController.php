<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\VkAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VkAuthController extends Controller
{
    public function __construct(private readonly VkAuthService $vkService) {}

    /**
     * GET /api/auth/vk/redirect
     */
    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        if (!$this->vkService->isConfigured()) {
            return response()->json(['message' => 'Вход через VK ID временно недоступен.'], 503);
        }

        $intent = (string) $request->query('intent', 'login');
        if (!in_array($intent, ['login', 'link'], true)) {
            return response()->json(['message' => 'Некорректный OAuth intent.'], 422);
        }

        if ($intent === 'link' && !$request->user()) {
            return response()->json(['message' => 'Для привязки требуется авторизация.'], 401);
        }

        $state = Str::random(40);
        $codeVerifier = $this->vkService->generateCodeVerifier();
        $codeChallenge = $this->vkService->generateCodeChallenge($codeVerifier);

        $request->session()->put('vk_oauth_state', $state);
        $request->session()->put('vk_oauth_code_verifier', $codeVerifier);
        $request->session()->put('vk_oauth_context', [
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'intent' => $intent,
            'provider' => 'vk',
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'redirect_url' => $this->vkService->getRedirectUrl($state, $codeChallenge),
        ]);
    }

    /**
     * GET /api/auth/vk/callback
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendBase = $this->resolveFrontendBase($request);

        try {
            $stateParam = $request->query('state');
            $state = is_string($stateParam) ? $stateParam : null;

            $context = $request->session()->pull('vk_oauth_context');
            $storedState = is_array($context) ? ($context['state'] ?? null) : null;
            $codeVerifier = is_array($context) ? ($context['code_verifier'] ?? null) : null;
            if (!$storedState) {
                $storedState = $request->session()->pull('vk_oauth_state');
                $codeVerifier = $request->session()->pull('vk_oauth_code_verifier');
                $context = ['intent' => 'login'];
            }
            $storedState = is_string($storedState) ? $storedState : null;
            $codeVerifier = is_string($codeVerifier) ? $codeVerifier : null;

            $intent = is_array($context) ? ($context['intent'] ?? 'login') : 'login';

            if (!$state || !$storedState || !hash_equals($storedState, $state)) {
                return redirect($frontendBase . '/login?error=oauth_state_mismatch&provider=vk');
            }

            $codeParam = $request->query('code');
            $code = is_string($codeParam) ? $codeParam : null;
            if (!$code) {
                return redirect($frontendBase . '/login?error=oauth_no_code&provider=vk');
            }

            $deviceIdParam = $request->query('device_id');
            $deviceId = is_string($deviceIdParam) ? $deviceIdParam : null;
            if (!$deviceId || !$codeVerifier) {
                return redirect($frontendBase . '/login?error=oauth_token_failed&provider=vk');
            }

            $tokenData = $this->vkService->exchangeCode($code, $codeVerifier, $deviceId, $state);
            if (!$tokenData || empty($tokenData['access_token'])) {
                return redirect($frontendBase . '/login?error=oauth_token_failed&provider=vk');
            }

            $profile = $this->vkService->getUserProfile($tokenData['access_token']);
            if (!$profile) {
                return redirect($frontendBase . '/login?error=oauth_profile_failed&provider=vk');
            }

            if ($intent === 'link') {
                $authUser = $request->user();
                $expectedUserId = is_array($context) ? (int) ($context['user_id'] ?? 0) : 0;

                if (!$authUser || ($expectedUserId > 0 && (int) $authUser->id !== $expectedUserId)) {
                    return redirect($frontendBase . '/projects?open_settings=security&oauth_link=auth_required&provider=vk');
                }

                $linked = $this->vkService->linkProfileToUser($authUser, $profile);

                if (!$linked['linked']) {
                    $error = $linked['error'] ?? 'failed';
                    return redirect($frontendBase . '/projects?open_settings=security&oauth_link=' . urlencode($error) . '&provider=vk');
                }

                return redirect($frontendBase . '/projects?open_settings=security&oauth_link=success&provider=vk');
            }

            $result = $this->vkService->findOrCreateUser($profile);
            $user = $result['user'];

            if (!$user) {
                $error = (string) ($result['error'] ?? 'oauth_profile_failed');
                if ($error === 'oauth_link_required') {
                    return redirect($frontendBase . '/login?mode=login&error=oauth_link_required&provider=vk');
                }
                if ($error === 'account_deleted') {
                    return redirect($frontendBase . '/login?error=account_deleted');
                }
                if ($error === 'account_blocked') {
                    return redirect($frontendBase . '/login?error=account_blocked');
                }

                return redirect($frontendBase . '/login?error=' . urlencode($error) . '&provider=vk');
            }

            if ($user->trashed()) {
                return redirect($frontendBase . '/login?error=account_deleted');
            }
            if ($user->isBlocked()) {
                return redirect($frontendBase . '/login?error=account_blocked');
            }

            Auth::login($user);
            $request->session()->regenerate();

            $currentSessionId = $request->session()->getId();
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $currentSessionId)
                ->delete();
            $user->update([
                'current_session_id' => $currentSessionId,
                'last_login_channel' => 'vk',
            ]);

            if (!$user->settings && $user->registration_completed_at) {
                try {
                    \App\Models\UserSettings::createForUser($user);
                } catch (\Throwable $e) {
                    Log::warning('[VkAuth] Failed to create default user settings after login', [
                        'user_id' => $user->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            if ($result['needs_onboarding']) {
                return redirect($frontendBase . '/login?mode=onboarding&via=vk');
            }

            return redirect($frontendBase . '/projects');
        } catch (\Throwable $e) {
            Log::error('[VkAuth] Callback unhandled error', [
                'message' => $e->getMessage(),
            ]);

            return redirect($frontendBase . '/login?error=oauth_server_error&provider=vk');
        }
    }

    protected function resolveFrontendBase(Request $request): string
    {
        $configured = (string) config('app.frontend_url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim($request->getSchemeAndHttpHost(), '/');
    }
}
