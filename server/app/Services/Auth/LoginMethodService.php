<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;

class LoginMethodService
{
    protected YandexAuthService $yandexAuthService;
    protected VkAuthService $vkAuthService;

    public function __construct(YandexAuthService $yandexAuthService, VkAuthService $vkAuthService)
    {
        $this->yandexAuthService = $yandexAuthService;
        $this->vkAuthService = $vkAuthService;
    }

    /**
     * @return array<int, array{provider: string, label: string, configured: bool}>
     */
    public function supportedProviders(): array
    {
        return [
            [
                'provider' => 'yandex',
                'label' => 'Яндекс',
                'configured' => $this->yandexAuthService->isConfigured(),
            ],
            [
                'provider' => 'vk',
                'label' => 'VK ID',
                'configured' => $this->vkAuthService->isConfigured(),
            ],
        ];
    }

    public function isProviderSupported(string $provider): bool
    {
        foreach ($this->supportedProviders() as $meta) {
            if ($meta['provider'] === $provider) {
                return true;
            }
        }

        return false;
    }

    public function isProviderConfigured(string $provider): bool
    {
        if ($provider === 'yandex') {
            return $this->yandexAuthService->isConfigured();
        }

        if ($provider === 'vk') {
            return $this->vkAuthService->isConfigured();
        }

        return false;
    }

    public function providerLabel(string $provider): string
    {
        foreach ($this->supportedProviders() as $meta) {
            if ($meta['provider'] === $provider) {
                return $meta['label'];
            }
        }

        return $provider;
    }

    public function hasPasswordMethod(User $user): bool
    {
        return !empty($user->email) && !empty($user->password);
    }

    public function hasPhoneMethod(User $user): bool
    {
        return !empty($user->phone) && $user->phone_verified_at !== null;
    }

    public function linkedProviders(User $user)
    {
        return $user->socialAccounts()
            ->where('is_active', true)
            ->orderBy('provider')
            ->get();
    }

    public function countLoginMethods(User $user, ?string $excludeProvider = null): int
    {
        $count = 0;

        if ($this->hasPasswordMethod($user)) {
            $count++;
        }

        if ($this->hasPhoneMethod($user)) {
            $count++;
        }

        $providersQuery = $user->socialAccounts()->where('is_active', true);
        if ($excludeProvider !== null) {
            $providersQuery->where('provider', '!=', $excludeProvider);
        }

        $count += (int) $providersQuery->count();

        return $count;
    }

    public function canUnlinkProvider(User $user, string $provider): bool
    {
        return $this->countLoginMethods($user, $provider) >= 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function linkedProvidersPayload(User $user): array
    {
        return $this->linkedProviders($user)
            ->map(function (SocialAccount $account) use ($user) {
                return [
                    'provider' => $account->provider,
                    'label' => $this->providerLabel($account->provider),
                    'provider_user_id' => $account->provider_user_id,
                    'provider_username' => $account->provider_username,
                    'provider_email' => $account->provider_email,
                    'provider_phone' => $account->provider_phone,
                    'linked_at' => $account->linked_at?->toIso8601String(),
                    'last_used_at' => $account->last_used_at?->toIso8601String(),
                    'can_unlink' => $this->canUnlinkProvider($user, $account->provider),
                ];
            })
            ->values()
            ->all();
    }
}
