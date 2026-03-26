<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Enums\CircuitState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker для LLM провайдеров (3-state)
 *
 * Состояния:
 *   CLOSED  — провайдер работает нормально
 *   OPEN    — провайдер недоступен, запросы блокируются
 *   HALF_OPEN — cooldown прошёл, пропускается 1 пробный запрос
 *
 * Правила перехода:
 *   CLOSED  → OPEN      : fail_count >= FAILURE_THRESHOLD
 *   OPEN    → HALF_OPEN : прошло RECOVERY_TIME_SECONDS
 *   HALF_OPEN → CLOSED  : 1 успешный ответ
 *   HALF_OPEN → OPEN    : 1 ошибка (контракт half-open)
 *
 * Хранение: Redis Cache, ключ llm:health:{provider}
 */
class CircuitBreaker
{
    private const CACHE_PREFIX = 'llm:health:';
    private const FAILURE_THRESHOLD = 3;
    private const RECOVERY_TIME_SECONDS = 120;
    private const STATE_TTL_SECONDS = 3600;

    /**
     * Проверить, доступен ли провайдер для запроса.
     *
     * CLOSED    → true
     * HALF_OPEN → true  (один пробный запрос)
     * OPEN      → false
     */
    public function isAvailable(string $provider): bool
    {
        $circuit = $this->getCircuitState($provider);

        if ($circuit === CircuitState::OPEN) {
            Log::debug("CircuitBreaker: {$provider} is OPEN — blocked");
            return false;
        }

        if ($circuit === CircuitState::HALF_OPEN) {
            Log::debug("CircuitBreaker: {$provider} is HALF_OPEN — allowing probe request");
        }

        return true;
    }

    /**
     * Текущее состояние circuit breaker.
     */
    public function getCircuitState(string $provider): CircuitState
    {
        $state = $this->getRawState($provider);

        if ($state === null) {
            return CircuitState::CLOSED;
        }

        $circuit = $state['circuit'] ?? CircuitState::CLOSED->value;
        $downUntil = $state['down_until'] ?? 0;

        // Автоматический переход OPEN → HALF_OPEN при истечении cooldown
        if ($circuit === CircuitState::OPEN->value && $downUntil > 0 && $downUntil <= time()) {
            $state['circuit'] = CircuitState::HALF_OPEN->value;
            $this->setRawState($provider, $state);
            Log::info("CircuitBreaker: {$provider} transitioned OPEN → HALF_OPEN");
            return CircuitState::HALF_OPEN;
        }

        return CircuitState::tryFrom($circuit) ?? CircuitState::CLOSED;
    }

    /**
     * Зарегистрировать ошибку провайдера.
     */
    public function recordFailure(string $provider, string $errorType): void
    {
        $state = $this->getRawState($provider) ?? $this->defaultState();

        $currentCircuit = CircuitState::tryFrom($state['circuit'] ?? CircuitState::CLOSED->value)
            ?? CircuitState::CLOSED;

        $state['fail_count'] = ($state['fail_count'] ?? 0) + 1;
        $state['last_error'] = $errorType;
        $state['last_failure_at'] = time();

        if ($currentCircuit === CircuitState::HALF_OPEN) {
            // Проба провалилась → обратно в OPEN
            $state['circuit'] = CircuitState::OPEN->value;
            $state['down_until'] = time() + self::RECOVERY_TIME_SECONDS;
            Log::warning("CircuitBreaker: {$provider} HALF_OPEN → OPEN (probe failed)", [
                'error_type' => $errorType,
            ]);
        } elseif ($state['fail_count'] >= self::FAILURE_THRESHOLD) {
            $state['circuit'] = CircuitState::OPEN->value;
            $state['down_until'] = time() + self::RECOVERY_TIME_SECONDS;
            Log::warning("CircuitBreaker: {$provider} CLOSED → OPEN (threshold reached)", [
                'fail_count' => $state['fail_count'],
                'error_type' => $errorType,
            ]);
        }

        $this->setRawState($provider, $state);
    }

    /**
     * Зарегистрировать успех провайдера.
     */
    public function recordSuccess(string $provider): void
    {
        $state = $this->getRawState($provider);

        if ($state === null) {
            return;
        }

        $prev = $state['circuit'] ?? CircuitState::CLOSED->value;

        $state['fail_count'] = 0;
        $state['down_until'] = 0;
        $state['circuit'] = CircuitState::CLOSED->value;
        $state['last_success_at'] = time();

        $this->setRawState($provider, $state);

        if ($prev === CircuitState::HALF_OPEN->value) {
            Log::info("CircuitBreaker: {$provider} HALF_OPEN → CLOSED (probe succeeded)");
        } else {
            Log::debug("CircuitBreaker: {$provider} marked HEALTHY");
        }
    }

    /**
     * Получить статистику провайдера для API/UI.
     */
    public function getStats(string $provider): array
    {
        $circuit = $this->getCircuitState($provider);
        $state = $this->getRawState($provider);

        return [
            'provider' => $provider,
            'status' => $circuit->label(),
            'circuit' => $circuit->value,
            'fail_count' => $state['fail_count'] ?? 0,
            'down_until' => ($state['down_until'] ?? 0) > time()
                ? date('c', $state['down_until'])
                : null,
            'last_error' => $state['last_error'] ?? null,
            'last_failure_at' => isset($state['last_failure_at'])
                ? date('c', $state['last_failure_at'])
                : null,
            'last_success_at' => isset($state['last_success_at'])
                ? date('c', $state['last_success_at'])
                : null,
        ];
    }

    /**
     * Сбросить состояние провайдера.
     */
    public function reset(string $provider): void
    {
        Cache::forget(self::CACHE_PREFIX . $provider);
        Log::info("CircuitBreaker: {$provider} state RESET");
    }

    // ---------------------------------------------------------------
    // Internal
    // ---------------------------------------------------------------

    private function getRawState(string $provider): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $provider);
    }

    private function setRawState(string $provider, array $state): void
    {
        Cache::put(self::CACHE_PREFIX . $provider, $state, self::STATE_TTL_SECONDS);
    }

    private function defaultState(): array
    {
        return [
            'circuit' => CircuitState::CLOSED->value,
            'fail_count' => 0,
            'down_until' => 0,
            'last_error' => null,
            'last_failure_at' => null,
            'last_success_at' => null,
        ];
    }
}
