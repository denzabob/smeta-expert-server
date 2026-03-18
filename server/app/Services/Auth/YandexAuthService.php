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
     * @return array{user: ?User, is_new: bool, needs_onboarding: bool, error: ?string}
     */
    public function findOrCreateUser(array $profile): array
    {
        $providerUserId = (string) $profile['id'];
        $providerEmail = $profile['default_email'] ?? null;
        $providerPhone = $profile['default_phone']['number'] ?? null;
        $displayName = $profile['display_name'] ?? $profile['real_name'] ?? $profile['login'] ?? '';
        $providerUsername = $profile['login'] ?? null;

        // 1. Check if social account already linked
        $social = SocialAccount::findByProvider('yandex', $providerUserId);
        if ($social) {
            $user = $social->user;
            // Update raw profile
            $social->update([
                'provider_username' => $providerUsername,
                'provider_email' => $providerEmail,
                'provider_phone' => $providerPhone,
                'last_used_at' => now(),
                'raw_profile_json' => $profile,
            ]);

            return [
                'user' => $user,
                'is_new' => false,
                'needs_onboarding' => !$user->registration_completed_at,
                'error' => null,
            ];
        }

        $inactiveLinked = SocialAccount::where('provider', 'yandex')
            ->where('provider_user_id', $providerUserId)
            ->where('is_active', false)
            ->first();

        if ($inactiveLinked) {
            return [
                'user' => null,
                'is_new' => false,
                'needs_onboarding' => false,
                'error' => 'provider_unlinked',
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
            'provider_username' => $providerUsername,
            'provider_email' => $providerEmail,
            'provider_phone' => $providerPhone,
            'linked_at' => now(),
            'last_used_at' => now(),
            'is_active' => true,
            'raw_profile_json' => $profile,
        ]);

        return [
            'user' => $user,
            'is_new' => $isNew,
            'needs_onboarding' => !$user->registration_completed_at,
            'error' => null,
        ];
    }

    /**
     * Link a Yandex profile to an already authenticated user.
     *
     * @return array{linked: bool, already_linked: bool, error: ?string}
     */
    public function linkProfileToUser(User $user, array $profile): array
    {
        $providerUserId = (string) ($profile['id'] ?? '');
        if ($providerUserId === '') {
            return [
                'linked' => false,
                'already_linked' => false,
                'error' => 'invalid_profile',
            ];
        }

        $providerEmail = $profile['default_email'] ?? null;
        $providerPhone = $profile['default_phone']['number'] ?? null;
        $providerUsername = $profile['login'] ?? null;

        $linkedByProviderId = SocialAccount::where('provider', 'yandex')
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linkedByProviderId && (int) $linkedByProviderId->user_id !== (int) $user->id) {
            return [
                'linked' => false,
                'already_linked' => false,
                'error' => 'already_linked_to_other_user',
            ];
        }

        $account = SocialAccount::where('provider', 'yandex')
            ->where('user_id', $user->id)
            ->first();

        $alreadyLinked = false;

        if (!$account) {
            $account = new SocialAccount();
            $account->user_id = $user->id;
            $account->provider = 'yandex';
            $account->linked_at = now();
        } else {
            $alreadyLinked = $account->is_active
                && $account->provider_user_id === $providerUserId;
        }

        $account->provider_user_id = $providerUserId;
        $account->provider_username = $providerUsername;
        $account->provider_email = $providerEmail;
        $account->provider_phone = $providerPhone;
        $account->last_used_at = now();
        $account->is_active = true;
        $account->raw_profile_json = $profile;
        $account->save();

        return [
            'linked' => true,
            'already_linked' => $alreadyLinked,
            'error' => null,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }
}
