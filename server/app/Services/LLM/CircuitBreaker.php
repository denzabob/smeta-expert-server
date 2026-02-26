<?php

declare(strict_types=1);

namespace App\Services\LLM;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker для LLM провайдеров
 * 
 * Реализация на Redis Cache:
 * - ключ: llm:health:{provider}
 * - хранит: fail_count, down_until timestamp
 * 
 * Правила:
 * - если 3 ошибки подряд → down_until = now + 120s
 * - router пропускает провайдер, если down_until > now
 * - при успешном ответе → fail_count = 0
 */
class CircuitBreaker
{
    private const CACHE_PREFIX = 'llm:health:';
    private const FAILURE_THRESHOLD = 3;
    private const RECOVERY_TIME_SECONDS = 120;
    private const STATE_TTL_SECONDS = 3600; // 1 hour max TTL

    /**
     * Проверить, доступен ли провайдер
     */
    public function isAvailable(string $provider): bool
    {
        $state = $this->getState($provider);

        if ($state === null) {
            return true;
        }

        $downUntil = $state['down_until'] ?? 0;

        if ($downUntil > 0 && $downUntil > time()) {
            Log::debug("CircuitBreaker: {$provider} is DOWN until " . date('H:i:s', $downUntil));
            return false;
        }

        return true;
    }

    /**
     * Зарегистрировать ошибку провайдера
     */
    public function recordFailure(string $provider, string $errorType): void
    {
        $state = $this->getState($provider) ?? ['fail_count' => 0, 'down_until' => 0];

        $state['fail_count']++;
        $state['last_error'] = $errorType;
        $state['last_failure_at'] = time();

        if ($state['fail_count'] >= self::FAILURE_THRESHOLD) {
            $state['down_until'] = time() + self::RECOVERY_TIME_SECONDS;
            Log::warning("CircuitBreaker: {$provider} marked DOWN for " . self::RECOVERY_TIME_SECONDS . "s", [
                'fail_count' => $state['fail_count'],
                'error_type' => $errorType,
            ]);
        }

        $this->setState($provider, $state);
    }

    /**
     * Зарегистрировать успех провайдера
     */
    public function recordSuccess(string $provider): void
    {
        $state = $this->getState($provider);

        if ($state === null) {
            return;
        }

        // Сбрасываем счетчик ошибок
        $state['fail_count'] = 0;
        $state['down_until'] = 0;
        $state['last_success_at'] = time();

        $this->setState($provider, $state);

        Log::debug("CircuitBreaker: {$provider} marked HEALTHY");
    }

    /**
     * Получить статистику провайдера
     */
    public function getStats(string $provider): array
    {
        $state = $this->getState($provider);

        if ($state === null) {
            return [
                'provider' => $provider,
                'status' => 'healthy',
                'fail_count' => 0,
                'down_until' => null,
            ];
        }

        $downUntil = $state['down_until'] ?? 0;
        $isDown = $downUntil > 0 && $downUntil > time();

        return [
            'provider' => $provider,
            'status' => $isDown ? 'down' : 'healthy',
            'fail_count' => $state['fail_count'] ?? 0,
            'down_until' => $isDown ? date('c', $downUntil) : null,
            'last_error' => $state['last_error'] ?? null,
            'last_failure_at' => isset($state['last_failure_at']) ? date('c', $state['last_failure_at']) : null,
            'last_success_at' => isset($state['last_success_at']) ? date('c', $state['last_success_at']) : null,
        ];
    }

    /**
     * Сбросить состояние провайдера (для тестов / админки)
     */
    public function reset(string $provider): void
    {
        Cache::forget(self::CACHE_PREFIX . $provider);
        Log::info("CircuitBreaker: {$provider} state RESET");
    }

    /**
     * Получить состояние из кеша
     */
    private function getState(string $provider): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $provider);
    }

    /**
     * Сохранить состояние в кеш
     */
    private function setState(string $provider, array $state): void
    {
        Cache::put(self::CACHE_PREFIX . $provider, $state, self::STATE_TTL_SECONDS);
    }
}
