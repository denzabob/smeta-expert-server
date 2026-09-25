<?php

declare(strict_types=1);

namespace App\Services\LLM;

/** Keeps task overrides separate from provider credentials and global routing. */
final class LLMTaskProfileResolver
{
    public const EXPERT_CHAT = 'expert_chat';
    public const EXPERT_FAST = 'expert_fast';
    public const EXPERT_DEEP = 'expert_deep';
    public const EXPERT_CONTEXT_RESOLVER = 'expert_context_resolver';

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

        if (($profile['fallback_enabled'] ?? false) === true
            && ProviderRegistry::exists((string) ($profile['fallback_provider'] ?? ''))
            && is_string($profile['fallback_model'] ?? null)
            && $profile['fallback_model'] !== '') {
            return array_values(array_unique([$primary, (string) $profile['fallback_provider']]));
        }

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
                'fallback_enabled' => $configured['fallback_enabled'] ?? false,
                'fallback_provider' => $configured['fallback_provider'] ?? null,
                'fallback_model' => $configured['fallback_model'] ?? null,
                'reasoning_effort' => $configured['reasoning_effort'] ?? null,
                'max_output_tokens' => $configured['max_output_tokens'] ?? null,
                'temperature' => $configured['temperature'] ?? null,
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

    /**
     * Resolve a public Expert mode profile without exposing provider details to
     * the client. Fast/Deep profiles are additive; an active legacy profile is
     * retained as the rollout fallback and the global route remains the final
     * compatibility fallback.
     */
    public function effectiveForExpertMode(string $task): ?array
    {
        if (! in_array($task, [self::EXPERT_FAST, self::EXPERT_DEEP], true)) {
            return null;
        }

        $profileTask = $task;
        $profileActive = $this->active($task) !== null;
        if (! $profileActive && $this->active(self::EXPERT_CHAT) !== null) {
            $profileTask = self::EXPERT_CHAT;
            $profileActive = true;
        }
        if (! $profileActive && $task === self::EXPERT_DEEP) {
            return null;
        }
        $effective = $this->effective($profileTask);
        $configured = $this->settings->getTaskProfile($task);
        if ($profileTask === self::EXPERT_CHAT && $configured === null) {
            $configured = $this->settings->getTaskProfile(self::EXPERT_CHAT);
        }

        $fallback = null;
        if ($profileTask === $task && is_array($configured) && ($configured['fallback_enabled'] ?? false) === true) {
            $provider = (string) ($configured['fallback_provider'] ?? '');
            $model = (string) ($configured['fallback_model'] ?? '');
            if (ProviderRegistry::exists($provider) && $model !== '') {
                $fallback = [
                    'enabled' => true,
                    'provider' => $provider,
                    'model' => $model,
                    'capabilities' => $this->capabilities->resolve($provider, $model),
                ];
            }
        }

        return [
            ...$effective,
            'profile_task' => $profileTask,
            'profile_active' => $profileActive,
            'public_profile' => $task,
            'fallback' => $fallback,
            'reasoning_effort' => is_string($configured['reasoning_effort'] ?? null) ? $configured['reasoning_effort'] : null,
            'max_output_tokens' => isset($configured['max_output_tokens']) ? (int) $configured['max_output_tokens'] : null,
            'temperature' => isset($configured['temperature']) ? (float) $configured['temperature'] : null,
        ];
    }
}
