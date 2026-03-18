<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class YandexAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;

    public function __construct()
    {
        $this->clientId = config('services.yandex.client_id') ?? '';
        $this->clientSecret = config('services.yandex.client_secret') ?? '';
        $this->redirectUri = config('services.yandex.redirect_uri') ?? '';
    }

    /**
     * Build the Yandex OAuth redirect URL.
     */
    public function getRedirectUrl(string $state): string
    {
        return 'https://oauth.yandex.ru/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'force_confirm' => 'yes',
        ]);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @return array{access_token: string, ...}|null
     */
    public function exchangeCode(string $code): ?array
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://oauth.yandex.ru/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('[YandexAuth] Token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[YandexAuth] Token exchange error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get user profile from Yandex.
     *
     * @return array{id: string, login: string, default_email: string, ...}|null
     */
    public function getUserProfile(string $accessToken): ?array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get('https://login.yandex.ru/info', [
                    'format' => 'json',
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('[YandexAuth] Profile fetch failed', [
                'status' => $response->status(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[YandexAuth] Profile fetch error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find or create user from Yandex profile.
     *
     * @return array{user: User, is_new: bool, needs_onboarding: bool}
     */
    public function findOrCreateUser(array $profile): array
    {
        $providerUserId = (string) $profile['id'];
        $providerEmail = $profile['default_email'] ?? null;
        $providerPhone = $profile['default_phone']['number'] ?? null;
        $displayName = $profile['display_name'] ?? $profile['real_name'] ?? $profile['login'] ?? '';

        // 1. Check if social account already linked
        $social = SocialAccount::findByProvider('yandex', $providerUserId);
        if ($social) {
            $user = $social->user;
            // Update raw profile
            $social->update([
                'provider_email' => $providerEmail,
                'provider_phone' => $providerPhone,
                'raw_profile_json' => $profile,
            ]);

            return [
                'user' => $user,
                'is_new' => false,
                'needs_onboarding' => !$user->registration_completed_at,
            ];
        }

        // 2. Try to find existing user by phone or email
        $user = null;
        if ($providerPhone) {
            $normalizedPhone = VerificationCodeService::normalizePhone($providerPhone);
            $user = User::where('phone', $normalizedPhone)->first();
        }
        if (!$user && $providerEmail) {
            $user = User::where('email', $providerEmail)->first();
        }

        $isNew = false;
        if (!$user) {
            // 3. Create new user
            $user = User::create([
                'name' => $displayName,
                'full_name' => $displayName,
                'email' => $providerEmail,
                'phone' => $providerPhone ? VerificationCodeService::normalizePhone($providerPhone) : null,
                'phone_verified_at' => $providerPhone ? now() : null,
                'email_verified_at' => $providerEmail ? now() : null,
                'auth_status' => 'active',
                'last_login_channel' => 'yandex',
            ]);
            $isNew = true;
        }

        // 4. Link social account
        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'yandex',
            'provider_user_id' => $providerUserId,
            'provider_email' => $providerEmail,
            'provider_phone' => $providerPhone,
            'raw_profile_json' => $profile,
        ]);

        return [
            'user' => $user,
            'is_new' => $isNew,
            'needs_onboarding' => !$user->registration_completed_at,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }
}
