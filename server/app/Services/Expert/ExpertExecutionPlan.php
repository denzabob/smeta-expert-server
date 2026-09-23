<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Services\LLM\DTO\LLMProfileFallbackSelection;

final readonly class ExpertExecutionPlan
{
    /** @param list<string> $requiredCapabilities @param list<string> $tools @param array<string, mixed> $fallback @param array<string, mixed> $requirements */
    public function __construct(
        public string $requestedMode,
        public string $resolvedMode,
        public string $routeReason,
        public string $profile,
        public string $provider,
        public string $model,
        public array $requiredCapabilities,
        public array $tools,
        public array $fallback,
        public array $requirements,
        public readonly ?string $routerProfile = null,
        public string $primaryProvider = '',
        public string $primaryModel = '',
        public ?LLMProfileFallbackSelection $fallbackSelection = null,
    ) {}

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'requested_mode' => $this->requestedMode,
            'resolved_mode' => $this->resolvedMode,
            'route_reason' => $this->routeReason,
            'profile' => $this->profile,
            'task_profile' => $this->profile,
            'primary_provider' => $this->primaryProvider,
            'primary_model' => $this->primaryModel,
            'selected_provider' => $this->provider,
            'selected_model' => $this->model,
            'effective_provider' => $this->provider,
            'effective_model' => $this->model,
            'fallback_used' => $this->fallbackSelection !== null,
            'fallback_reason' => $this->fallbackSelection === null ? null : 'capability_mismatch',
            'required_capabilities' => $this->requiredCapabilities,
            'tools' => $this->tools,
            'fallback' => $this->fallback,
            ...$this->requirements,
        ];
    }

    public function executionStrategy(): string
    {
        return is_string($this->requirements['execution_strategy'] ?? null)
            ? $this->requirements['execution_strategy']
            : ExpertAnalysisExecutionStrategy::DIRECT;
    }

    public function requiresExecutionPipeline(): bool
    {
        return ExpertAnalysisExecutionStrategy::requiresPipeline($this->executionStrategy());
    }

    public function withCoverage(ExpertAnalysisCoverage $coverage): self
    {
        return new self(
            requestedMode: $this->requestedMode,
            resolvedMode: $this->resolvedMode,
            routeReason: $this->routeReason,
            profile: $this->profile,
            provider: $this->provider,
            model: $this->model,
            requiredCapabilities: $this->requiredCapabilities,
            tools: $this->tools,
            fallback: $this->fallback,
            requirements: [...$this->requirements, ...$coverage->toMetadata()],
            routerProfile: $this->routerProfile,
            primaryProvider: $this->primaryProvider,
            primaryModel: $this->primaryModel,
            fallbackSelection: $this->fallbackSelection,
        );
    }
}
