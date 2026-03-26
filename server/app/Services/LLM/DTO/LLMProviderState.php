<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

use App\Services\LLM\Enums\CircuitState;
use App\Services\LLM\Enums\UnavailableReason;

/**
 * Единый доменный объект состояния LLM-провайдера.
 *
 * Агрегирует данные из LLMSettingsRepository, CircuitBreaker, ProviderRegistry.
 */
final class LLMProviderState
{
    public function __construct(
        public readonly string $provider,
        public readonly string $displayName,
        public readonly bool $isConfigured,
        public readonly bool $isHealthy,
        public readonly bool $isAvailable,
        public readonly CircuitState $circuitState,
        public readonly int $failCount,
        public readonly ?string $lastError,
        public readonly ?string $lastErrorAt,
        public readonly string $source, // 'db' | 'env' | 'none'
        public readonly string $model,
        public readonly string $baseUrl,
        public readonly int $priority,
        public readonly bool $usedInChain,
        public readonly UnavailableReason $unavailableReason = UnavailableReason::NONE,
        public readonly ?float $avgLatencyMs = null,
        public readonly ?float $errorRate = null,
        public readonly ?string $lastSuccessAt = null,
        public readonly ?float $usagePercentage = null,
    ) {}

    public function status(): string
    {
        if (!$this->isConfigured) {
            return 'misconfigured';
        }
        if ($this->circuitState === CircuitState::OPEN) {
            return 'down';
        }
        if ($this->circuitState === CircuitState::HALF_OPEN) {
            return 'recovering';
        }
        return 'healthy';
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'display_name' => $this->displayName,
            'status' => $this->status(),
            'configured' => $this->isConfigured,
            'healthy' => $this->isHealthy,
            'available' => $this->isAvailable,
            'unavailable_reason' => $this->unavailableReason !== UnavailableReason::NONE
                ? $this->unavailableReason->value
                : null,
            'unavailable_reason_label' => $this->unavailableReason !== UnavailableReason::NONE
                ? $this->unavailableReason->label()
                : null,
            'circuit' => $this->circuitState->value,
            'fail_count' => $this->failCount,
            'last_error' => $this->lastError,
            'last_error_at' => $this->lastErrorAt,
            'last_success_at' => $this->lastSuccessAt,
            'source' => $this->source,
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'priority' => $this->priority,
            'used_in_chain' => $this->usedInChain,
            'latency_ms' => $this->avgLatencyMs !== null ? (int) round($this->avgLatencyMs) : null,
            'error_rate' => $this->errorRate !== null ? round($this->errorRate, 4) : null,
            'usage_percentage' => $this->usagePercentage !== null ? round($this->usagePercentage, 4) : null,
        ];
    }
}
