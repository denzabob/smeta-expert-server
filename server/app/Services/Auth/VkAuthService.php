<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VkAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $scope;

    public function __construct()
    {
        $this->clientId = config('services.vk.client_id') ?? '';
        $this->clientSecret = config('services.vk.client_secret') ?? '';
        $this->redirectUri = config('services.vk.redirect_uri') ?? '';
        $this->scope = config('services.vk.scope') ?? 'vkid.personal_info email phone';
    }

    /**
     * Generate a PKCE code verifier.
     */
    public function generateCodeVerifier(): string
    {
        return $this->base64UrlEncode(random_bytes(64));
    }

    /**
     * Generate a PKCE S256 code challenge.
     */
    public function generateCodeChallenge(string $codeVerifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    /**
     * Build the VK ID OAuth redirect URL.
     */
    public function getRedirectUrl(string $state, string $codeChallenge): string
    {
        return 'https://id.vk.com/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @return array{access_token: string, ...}|null
     */
    public function exchangeCode(string $code, string $codeVerifier, string $deviceId, string $state): ?array
    {
        try {
            $payload = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $codeVerifier,
                'device_id' => $deviceId,
                'redirect_uri' => $this->redirectUri,
                'client_id' => $this->clientId,
                'state' => $state,
            ];

            if ($this->clientSecret !== '') {
                $payload['client_secret'] = $this->clientSecret;
            }

            $response = Http::asForm()
                ->timeout(10)
                ->post('https://id.vk.com/oauth2/auth', $payload);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('[VkAuth] Token exchange failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[VkAuth] Token exchange error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get user profile from VK ID.
     */
    public function getUserProfile(string $accessToken): ?array
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://id.vk.com/oauth2/user_info', [
                    'access_token' => $accessToken,
                    'client_id' => $this->clientId,
                ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('[VkAuth] Profile fetch failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::error('[VkAuth] Profile fetch error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find or create user from VK ID profile.
     *
     * @return array{user: ?User, is_new: bool, needs_onboarding: bool, error: ?string}
     */
    public function findOrCreateUser(array $profile): array
    {
        $normalized = $this->normalizeProfile($profile);
        $providerUserId = $normalized['id'];
        $providerEmail = $normalized['email'];
        $providerPhone = $normalized['phone'];
        $displayName = $normalized['display_name'];
        $providerUsername = $normalized['username'];

        if ($providerUserId === '') {
            return [
                'user' => null,
                'is_new' => false,
                'needs_onboarding' => false,
                'error' => 'oauth_profile_failed',
            ];
        }

        $social = SocialAccount::findByProvider('vk', $providerUserId);
        if ($social) {
            $user = User::withTrashed()->find($social->user_id);

            if ($user && $user->trashed()) {
                return [
                    'user' => null,
                    'is_new' => false,
                    'needs_onboarding' => false,
                    'error' => 'account_deleted',
                ];
            }
            if ($user && $user->isBlocked()) {
                return [
                    'user' => null,
                    'is_new' => false,
                    'needs_onboarding' => false,
                    'error' => 'account_blocked',
                ];
            }

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
                'needs_onboarding' => $user ? !$user->registration_completed_at : false,
                'error' => $user ? null : 'oauth_profile_failed',
            ];
        }

        $inactiveLinked = SocialAccount::where('provider', 'vk')
            ->where('provider_user_id', $providerUserId)
            ->where('is_active', false)
            ->first();

        if ($inactiveLinked) {
            Log::info('[VkAuth] provider login attempted with unlinked identity', [
                'provider' => 'vk',
                'provider_user_id' => $providerUserId,
                'user_id' => $inactiveLinked->user_id,
            ]);

            $user = User::withTrashed()->find($inactiveLinked->user_id);

            if ($user && $user->trashed()) {
                return [
                    'user' => null,
                    'is_new' => false,
                    'needs_onboarding' => false,
                    'error' => 'account_deleted',
                ];
            }
            if ($user && $user->isBlocked()) {
                return [
                    'user' => null,
                    'is_new' => false,
                    'needs_onboarding' => false,
                    'error' => 'account_blocked',
                ];
            }
            if ($user) {
                $inactiveLinked->update([
                    'is_active' => true,
                    'unlinked_at' => null,
                    'linked_at' => now(),
                    'last_used_at' => now(),
                    'provider_username' => $providerUsername,
                    'provider_email' => $providerEmail,
                    'provider_phone' => $providerPhone,
                    'raw_profile_json' => $profile,
                ]);

                Log::info('[VkAuth] provider relinked', [
                    'provider' => 'vk',
                    'provider_user_id' => $providerUserId,
                    'user_id' => $user->id,
                    'source' => 'guest_login_reactivate',
                ]);

                return [
                    'user' => $user,
                    'is_new' => false,
                    'needs_onboarding' => !$user->registration_completed_at,
                    'error' => null,
                ];
            }

            return [
                'user' => null,
                'is_new' => false,
                'needs_onboarding' => false,
                'error' => 'oauth_link_required',
            ];
        }

        $matchedUser = null;
        if ($providerPhone) {
            $normalizedPhone = VerificationCodeService::normalizePhone($providerPhone);
            $matchedUser = User::withTrashed()->where('phone', $normalizedPhone)->first();
        }
        if (!$matchedUser && $providerEmail) {
            $matchedUser = User::withTrashed()->where('email', $providerEmail)->first();
        }

        if ($matchedUser) {
            if ($matchedUser->trashed()) {
                return [
                    'user' => null,
                    'is_new' => false,
                    'needs_onboarding' => false,
                    'error' => 'account_deleted',
                ];
            }
            if ($matchedUser->isBlocked()) {
                return [
                    'user' => null,
                    'is_new' => false,
                    'needs_onboarding' => false,
                    'error' => 'account_blocked',
                ];
            }

            Log::info('[VkAuth] login requires manual link for existing account', [
                'provider' => 'vk',
                'provider_user_id' => $providerUserId,
                'matched_user_id' => $matchedUser->id,
            ]);

            return [
                'user' => null,
                'is_new' => false,
                'needs_onboarding' => false,
                'error' => 'oauth_link_required',
            ];
        }

        $user = User::create([
            'name' => $displayName,
            'full_name' => $displayName,
            'email' => $providerEmail,
            'phone' => $providerPhone ? VerificationCodeService::normalizePhone($providerPhone) : null,
            'phone_verified_at' => $providerPhone ? now() : null,
            'email_verified_at' => $providerEmail ? now() : null,
            'auth_status' => 'active',
            'last_login_channel' => 'vk',
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'vk',
            'provider_user_id' => $providerUserId,
            'provider_username' => $providerUsername,
            'provider_email' => $providerEmail,
            'provider_phone' => $providerPhone,
            'linked_at' => now(),
            'last_used_at' => now(),
            'is_active' => true,
            'unlinked_at' => null,
            'raw_profile_json' => $profile,
        ]);

        return [
            'user' => $user,
            'is_new' => true,
            'needs_onboarding' => !$user->registration_completed_at,
            'error' => null,
        ];
    }

    /**
     * Link a VK ID profile to an already authenticated user.
     *
     * @return array{linked: bool, already_linked: bool, error: ?string}
     */
    public function linkProfileToUser(User $user, array $profile): array
    {
        $normalized = $this->normalizeProfile($profile);
        $providerUserId = $normalized['id'];

        if ($providerUserId === '') {
            return [
                'linked' => false,
                'already_linked' => false,
                'error' => 'invalid_profile',
            ];
        }

        $linkedByProviderId = SocialAccount::where('provider', 'vk')
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linkedByProviderId && (int) $linkedByProviderId->user_id !== (int) $user->id) {
            Log::warning('[VkAuth] provider link conflict', [
                'provider' => 'vk',
                'provider_user_id' => $providerUserId,
                'current_user_id' => $user->id,
                'linked_user_id' => $linkedByProviderId->user_id,
            ]);

            return [
                'linked' => false,
                'already_linked' => false,
                'error' => 'already_linked_to_other_user',
            ];
        }

        $account = SocialAccount::where('provider', 'vk')
            ->where('user_id', $user->id)
            ->first();

        $alreadyLinked = false;
        $wasExistingInactive = false;

        if (!$account) {
            $account = new SocialAccount();
            $account->user_id = $user->id;
            $account->provider = 'vk';
            $account->linked_at = now();
        } elseif ($account->is_active && $account->provider_user_id !== $providerUserId) {
            return [
                'linked' => false,
                'already_linked' => false,
                'error' => 'provider_already_connected',
            ];
        } else {
            $wasExistingInactive = !$account->is_active;
            $alreadyLinked = $account->is_active
                && $account->provider_user_id === $providerUserId;
        }

        if ($account->exists && !$account->is_active) {
            $account->linked_at = now();
        }

        $account->provider_user_id = $providerUserId;
        $account->provider_username = $normalized['username'];
        $account->provider_email = $normalized['email'];
        $account->provider_phone = $normalized['phone'];
        $account->last_used_at = now();
        $account->is_active = true;
        $account->unlinked_at = null;
        $account->raw_profile_json = $profile;
        $account->save();

        if ($wasExistingInactive && !$alreadyLinked) {
            Log::info('[VkAuth] provider relinked', [
                'provider' => 'vk',
                'provider_user_id' => $providerUserId,
                'user_id' => $user->id,
                'source' => 'settings_link_flow',
            ]);
        }

        return [
            'linked' => true,
            'already_linked' => $alreadyLinked,
            'error' => null,
        ];
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId)
            && !empty($this->clientSecret)
            && !empty($this->redirectUri);
    }

    /**
     * @return array{id: string, email: ?string, phone: ?string, username: ?string, display_name: string}
     */
    protected function normalizeProfile(array $profile): array
    {
        $user = is_array($profile['user'] ?? null) ? $profile['user'] : $profile;

        $id = (string) (
            $user['user_id']
            ?? $user['id']
            ?? $profile['user_id']
            ?? $profile['id']
            ?? ''
        );

        $email = $this->nullableString(
            $user['email']
            ?? $profile['email']
            ?? null
        );

        $phone = $this->nullableString(
            $user['phone']
            ?? $user['phone_number']
            ?? $profile['phone']
            ?? $profile['phone_number']
            ?? null
        );

        $firstName = $this->nullableString($user['first_name'] ?? $profile['first_name'] ?? null);
        $lastName = $this->nullableString($user['last_name'] ?? $profile['last_name'] ?? null);
        $username = $this->nullableString(
            $user['screen_name']
            ?? $user['username']
            ?? $user['login']
            ?? $profile['screen_name']
            ?? $profile['username']
            ?? $profile['login']
            ?? null
        );

        $displayName = trim((string) (
            $user['name']
            ?? $profile['name']
            ?? trim(($firstName ?? '') . ' ' . ($lastName ?? ''))
            ?: $username
            ?: ($email ? Str::before($email, '@') : '')
        ));

        return [
            'id' => $id,
            'email' => $email,
            'phone' => $phone,
            'username' => $username,
            'display_name' => $displayName,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
