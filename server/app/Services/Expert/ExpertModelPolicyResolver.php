<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\DTO\LLMProfileFallbackSelection;
use App\Services\LLM\Enums\LLMCapability;
use App\Services\LLM\LLMEffectiveCapabilityResolver;
use App\Services\LLM\LLMTaskProfileResolver;
use App\Services\LLM\RouterAiModelCatalogService;
use Illuminate\Support\Facades\Log;

final class ExpertModelPolicyResolver
{
    public function __construct(
        private readonly LLMTaskProfileResolver $profiles,
        private readonly LLMEffectiveCapabilityResolver $capabilities,
        private readonly RouterAiModelCatalogService $catalog,
    ) {}

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
        $statusBefore = $this->catalog->cachedStatus();
        $primaryBefore = $this->capabilityState($effective['effective'], $effective['capabilities'] ?? [], $requiredCapabilities);
        $refreshAttempted = false;
        if (($effective['profile_active'] ?? false) && $primaryBefore !== 'supported'
            && ($this->needsRefresh($effective['effective'], $requiredCapabilities)
                || (is_array($effective['fallback'] ?? null) && $this->needsRefresh($effective['fallback'], $requiredCapabilities)))) {
            $refreshAttempted = true;
            $this->catalog->snapshot(true);
            request()->attributes->set('expert_routerai_catalog_refresh_attempted', true);
            $effective = $this->profiles->effectiveForExpertMode($task);
            if ($effective === null) {
                throw ExpertModelPolicyException::profileUnavailable($mode->resolvedMode);
            }
        }

        $selected = $effective['effective'];
        $fallbackSelection = null;
        $primaryAfter = $this->capabilityState($selected, $effective['capabilities'] ?? [], $requiredCapabilities);
        $fallback = $effective['fallback'] ?? null;
        $fallbackAfter = is_array($fallback) ? $this->capabilityState($fallback, $fallback['capabilities'] ?? [], $requiredCapabilities) : null;
        if (($effective['profile_active'] ?? false) && $primaryAfter === 'unsupported') {
            $fallback = $effective['fallback'] ?? null;
            if (! is_array($fallback) || ! ($fallback['enabled'] ?? false) || $fallbackAfter === 'unsupported') {
                $this->logDecision($task, $statusBefore, $refreshAttempted, $primaryBefore, $primaryAfter, $fallbackAfter, 'rejected', false);
                throw ExpertModelPolicyException::capabilityUnavailable();
            }
            $selected = $fallback;
            $fallbackSelection = new LLMProfileFallbackSelection((string) $fallback['provider'], (string) $fallback['model']);
        }

        if ($primaryBefore !== 'supported' || $refreshAttempted) {
            $this->logDecision($task, $statusBefore, $refreshAttempted, $primaryBefore, $primaryAfter, $fallbackAfter,
                $fallbackSelection !== null ? 'fallback' : 'primary', $fallbackSelection !== null);
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

    /** @param array<string, mixed> $candidate @param list<string> $required */
    private function needsRefresh(array $candidate, array $required): bool
    {
        if (($candidate['provider'] ?? null) !== 'routerai') {
            return false;
        }
        $model = (string) ($candidate['model'] ?? '');
        $capabilities = $candidate['capabilities'] ?? $this->capabilities->resolve('routerai', $model);
        foreach ($required as $capability) {
            if (($capabilities[$capability] ?? false) !== true
                && ($this->catalog->cachedStatus() !== 'fresh' || $this->catalog->cachedModel($model) === null)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $candidate @param array<string, bool> $capabilities @param list<string> $required */
    private function capabilityState(array $candidate, array $capabilities, array $required): string
    {
        $unknown = false;
        foreach ($required as $name) {
            if (($capabilities[$name] ?? false) === true) {
                continue;
            }
            $provider = (string) ($candidate['provider'] ?? '');
            $model = (string) ($candidate['model'] ?? '');
            if ($provider !== 'routerai') {
                return 'unsupported';
            }
            $capability = LLMCapability::tryFrom($name);
            if ($capability === null) {
                return 'unsupported';
            }
            $source = $this->capabilities->source($provider, $model, $capability);
            if ($capability === LLMCapability::PDF_OCR) {
                if (! config('expert.pdf_ocr.enabled', true)) {
                    return 'unsupported';
                }
                $source = $this->capabilities->source($provider, $model, LLMCapability::TEXT_INPUT);
            }
            if ($source === 'local_override' || ($source === 'dynamic_catalog' && $this->catalog->cachedStatus() === 'fresh')) {
                return 'unsupported';
            }
            $unknown = true;
        }

        return $unknown ? 'unknown' : 'supported';
    }

    private function logDecision(string $task, string $before, bool $refreshAttempted, string $primaryBefore, string $primaryAfter, ?string $fallbackAfter, string $selection, bool $fallbackUsed): void
    {
        Log::info('ExpertModelPolicy: capability preflight decision.', [
            'task_profile' => $task,
            'catalog_status_before' => $before,
            'catalog_refresh_attempted' => $refreshAttempted,
            'catalog_status_after' => $this->catalog->cachedStatus(),
            'primary_capability_before' => $primaryBefore,
            'primary_capability_after' => $primaryAfter,
            'fallback_capability_after' => $fallbackAfter,
            'selection' => $selection,
            'fallback_used' => $fallbackUsed,
        ]);
    }
}
