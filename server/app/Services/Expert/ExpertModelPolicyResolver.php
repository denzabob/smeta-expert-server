<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\DTO\LLMProfileFallbackSelection;
use App\Services\LLM\LLMTaskProfileResolver;

final class ExpertModelPolicyResolver
{
    public function __construct(private readonly LLMTaskProfileResolver $profiles) {}

    public function resolve(ExpertModeResolution $mode, ExpertTaskRequirements $requirements, ExpertToolPlan $tools): ExpertExecutionPlan
    {
        $task = $mode->resolvedMode === ExpertModeResolution::DEEP
            ? LLMTaskProfileResolver::EXPERT_DEEP
            : LLMTaskProfileResolver::EXPERT_FAST;
        $effective = $this->profiles->effectiveForExpertMode($task);
        if ($effective === null) {
            throw ExpertModelPolicyException::profileUnavailable($mode->resolvedMode);
        }

        $requiredCapabilities = $tools->requiredCapabilities;
        $selected = $effective['effective'];
        $fallbackSelection = null;
        if (($effective['profile_active'] ?? false) && ! $this->supportsAll($effective['capabilities'] ?? [], $requiredCapabilities)) {
            $fallback = $effective['fallback'] ?? null;
            if (! is_array($fallback) || ! ($fallback['enabled'] ?? false) || ! $this->supportsAll($fallback['capabilities'] ?? [], $requiredCapabilities)) {
                throw ExpertModelPolicyException::capabilityUnavailable();
            }
            $selected = $fallback;
            $fallbackSelection = new LLMProfileFallbackSelection((string) $fallback['provider'], (string) $fallback['model']);
        }

        $fallback = is_array($effective['fallback'] ?? null) ? $effective['fallback'] : [];

        return new ExpertExecutionPlan(
            requestedMode: $mode->requestedMode,
            resolvedMode: $mode->resolvedMode,
            routeReason: $mode->routeReason,
            profile: $task,
            provider: (string) ($selected['provider'] ?? ''),
            model: (string) ($selected['model'] ?? ''),
            requiredCapabilities: $requiredCapabilities,
            tools: $tools->tools,
            fallback: [
                'enabled' => (bool) ($fallback['enabled'] ?? false),
                'provider' => $fallback['provider'] ?? null,
                'model' => $fallback['model'] ?? null,
            ],
            requirements: $requirements->toMetadata(),
            routerProfile: ($effective['profile_active'] ?? false) ? (string) ($effective['profile_task'] ?? $task) : null,
            primaryProvider: (string) ($effective['effective']['provider'] ?? ''),
            primaryModel: (string) ($effective['effective']['model'] ?? ''),
            fallbackSelection: $fallbackSelection,
        );
    }

    /** @param array<string, bool> $capabilities @param list<string> $required */
    private function supportsAll(array $capabilities, array $required): bool
    {
        foreach ($required as $capability) {
            if (($capabilities[$capability] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }
}
