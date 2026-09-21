<?php

declare(strict_types=1);

namespace App\Services\Expert;

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
    ) {}

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'requested_mode' => $this->requestedMode,
            'resolved_mode' => $this->resolvedMode,
            'route_reason' => $this->routeReason,
            'profile' => $this->profile,
            'effective_provider' => $this->provider,
            'effective_model' => $this->model,
            'required_capabilities' => $this->requiredCapabilities,
            'tools' => $this->tools,
            'fallback' => $this->fallback,
            ...$this->requirements,
        ];
    }
}
