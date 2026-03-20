<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\YandexAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class YandexAuthController extends Controller
{
    protected YandexAuthService $yandexService;

    public function __construct(YandexAuthService $yandexService)
    {
        $this->yandexService = $yandexService;
    }

    /**
     * GET /api/auth/yandex/redirect
     *
     * Redirect user to Yandex OAuth consent screen.
     */
    public function redirect(Request $request): JsonResponse|RedirectResponse
    {
        if (!$this->yandexService->isConfigured()) {
            return response()->json(['message' => 'Вход через Яндекс временно недоступен.'], 503);
        }

        $intent = (string) $request->query('intent', 'login');
        if (!in_array($intent, ['login', 'link'], true)) {
            return response()->json(['message' => 'Некорректный OAuth intent.'], 422);
        }

        if ($intent === 'link' && !$request->user()) {
            return response()->json(['message' => 'Для привязки требуется авторизация.'], 401);
        }

        // Generate and store state for CSRF protection
        $state = Str::random(40);
        $request->session()->put('yandex_oauth_state', $state);
        $request->session()->put('yandex_oauth_context', [
            'state' => $state,
            'intent' => $intent,
            'provider' => 'yandex',
            'user_id' => $request->user()?->id,
        ]);

        $url = $this->yandexService->getRedirectUrl($state);

        // Return URL for SPA to redirect to
        return response()->json(['redirect_url' => $url]);
    }

    /**
     * GET /api/auth/yandex/callback
     *
     * Handle the callback from Yandex OAuth.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query('state');
        $context = $request->session()->pull('yandex_oauth_context');
        $storedState = is_array($context) ? ($context['state'] ?? null) : null;
        if (!$storedState) {
            // Backward compatibility with older sessions.
            $storedState = $request->session()->pull('yandex_oauth_state');
            $context = ['intent' => 'login'];
        }

        $intent = is_array($context) ? ($context['intent'] ?? 'login') : 'login';
        $frontendBase = $this->resolveFrontendBase($request);

        // Validate state
        if (!$state || !$storedState || !hash_equals($storedState, $state)) {
            return redirect($frontendBase . '/login?error=oauth_state_mismatch');
        }

        $code = $request->query('code');
        if (!$code) {
            return redirect($frontendBase . '/login?error=oauth_no_code');
        }

        // Exchange code for token
        $tokenData = $this->yandexService->exchangeCode($code);
        if (!$tokenData || empty($tokenData['access_token'])) {
            return redirect($frontendBase . '/login?error=oauth_token_failed');
        }

        // Get user profile
        $profile = $this->yandexService->getUserProfile($tokenData['access_token']);
        if (!$profile || empty($profile['id'])) {
            return redirect($frontendBase . '/login?error=oauth_profile_failed');
        }

        if ($intent === 'link') {
            $authUser = $request->user();
            $expectedUserId = is_array($context) ? (int) ($context['user_id'] ?? 0) : 0;

            if (!$authUser || ($expectedUserId > 0 && (int) $authUser->id !== $expectedUserId)) {
                return redirect($frontendBase . '/projects?open_settings=security&oauth_link=auth_required&provider=yandex');
            }

            $linked = $this->yandexService->linkProfileToUser($authUser, $profile);

            if (!$linked['linked']) {
                $error = $linked['error'] ?? 'failed';
                return redirect($frontendBase . '/projects?open_settings=security&oauth_link=' . urlencode($error) . '&provider=yandex');
            }

            return redirect($frontendBase . '/projects?open_settings=security&oauth_link=success&provider=yandex');
        }

        // Find or create user
        $result = $this->yandexService->findOrCreateUser($profile);
        $user = $result['user'];

        if (!$user) {
            $error = (string) ($result['error'] ?? 'oauth_profile_failed');
            if ($error === 'oauth_link_required') {
                return redirect($frontendBase . '/login?mode=login&error=oauth_link_required&provider=yandex');
            }
            if ($error === 'account_deleted') {
                return redirect($frontendBase . '/login?error=account_deleted');
            }
            if ($error === 'account_blocked') {
                return redirect($frontendBase . '/login?error=account_blocked');
            }

            return redirect($frontendBase . '/login?error=' . urlencode($error));
        }

        // Check blocked/deleted before login
        if ($user->trashed()) {
            return redirect($frontendBase . '/login?error=account_deleted');
        }
        if ($user->isBlocked()) {
            return redirect($frontendBase . '/login?error=account_blocked');
        }

        // Log in
        Auth::login($user);
        $request->session()->regenerate();

        // Single-session enforcement
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $user->update([
            'current_session_id' => $currentSessionId,
            'last_login_channel' => 'yandex',
        ]);

        // Create default settings if needed
        if (!$user->settings && $user->registration_completed_at) {
            \App\Models\UserSettings::createForUser($user);
        }

        // Redirect to frontend
        if ($result['needs_onboarding']) {
            return redirect($frontendBase . '/login?mode=onboarding&via=yandex');
        }

        return redirect($frontendBase . '/projects');
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
