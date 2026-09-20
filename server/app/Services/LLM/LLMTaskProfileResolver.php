<?php

declare(strict_types=1);

namespace App\Services\LLM;

/** Keeps task overrides separate from provider credentials and global routing. */
final class LLMTaskProfileResolver
{
    public const EXPERT_CHAT = 'expert_chat';

    public function __construct(
        private readonly LLMSettingsRepository $settings,
        private readonly LLMEffectiveCapabilityResolver $capabilities,
        private readonly RouterAiModelCatalogService $catalog,
    ) {}

    public function active(string $task): ?array
    {
        $profile = $this->settings->getTaskProfile($task);

        return is_array($profile) && ($profile['enabled'] ?? false) === true
            && ProviderRegistry::exists((string) ($profile['provider'] ?? ''))
            && is_string($profile['model'] ?? null) && $profile['model'] !== ''
            ? $profile : null;
    }

    /** @return list<string> */
    public function executionPlan(string $task): array
    {
        $profile = $this->active($task);
        if ($profile === null) {
            $primary = $this->settings->getPrimaryProvider();

            return $this->settings->getMode() === 'manual'
                ? [$primary]
                : array_values(array_unique([$primary, ...$this->settings->getFallbackProviders()]));
        }

        $primary = $profile['provider'];

        return ($profile['fallback_policy'] ?? 'none') === 'global' && $this->settings->getMode() === 'auto'
            ? array_values(array_unique([$primary, ...$this->settings->getFallbackProviders()]))
            : [$primary];
    }

    public function effective(string $task): array
    {
        $configured = $this->settings->getTaskProfile($task);
        $profile = $this->active($task);
        $provider = $profile['provider'] ?? $this->settings->getPrimaryProvider();
        $model = $profile['model'] ?? ($this->settings->getProviderSettings($provider)['model'] ?? ProviderRegistry::getDefaultModel($provider));

        return [
            'task' => $task,
            'configured' => $configured === null ? null : [
                'provider' => $configured['provider'] ?? null,
                'model' => $configured['model'] ?? null,
                'enabled' => $configured['enabled'] ?? false,
                'fallback_policy' => $configured['fallback_policy'] ?? 'none',
            ],
            'effective' => ['provider' => $provider, 'model' => $model],
            'source' => $profile !== null ? 'PROFILE' : $this->settings->getGlobalModelSource($provider),
            'provider_key_source' => $this->settings->getProviderKeySource($provider),
            'provider_configured' => $this->settings->getProviderKeySource($provider) !== 'NONE',
            'fallback_policy' => $profile['fallback_policy'] ?? ($this->settings->getMode() === 'auto' ? 'global' : 'none'),
            'capabilities' => $this->capabilities->resolve($provider, $model),
            'catalog_status' => $provider === 'routerai' ? $this->catalog->cachedStatus() : 'unsupported',
            'model_in_catalog' => $provider === 'routerai' ? $this->catalog->cachedModel($model) !== null : null,
        ];
    }
}
