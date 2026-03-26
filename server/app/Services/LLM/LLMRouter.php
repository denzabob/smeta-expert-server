<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Enums\LLMErrorType;
use App\Services\LLM\Exceptions\InvalidLLMJsonException;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Exceptions\LLMUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * Роутер LLM запросов с поддержкой failover, retry и structured logging.
 *
 * Поведение детерминировано:
 *   1. Строится execution plan: [primary, ...fallback]
 *   2. Для каждого провайдера:
 *      — skip если circuit breaker OPEN
 *      — skip если не сконфигурирован
 *      — отправить запрос (+ retry для retryable ошибок)
 *      — при auth/config → fail-fast, без retry
 *   3. Если все провайдеры провалились → LLMUnavailableException
 *
 * Режимы:
 *   manual — только primary, без failover
 *   auto   — primary + fallback цепочка
 */
class LLMRouter
{
    private const MAX_ATTEMPTS_PER_PROVIDER = 2;
    private const MAX_JSON_RETRY_ATTEMPTS = 1;
    private const RETRY_BASE_DELAY_MS = 200;
    private const RETRY_JITTER_MAX_MS = 100;

    private CircuitBreaker $circuitBreaker;
    private LLMSettingsRepository $settings;
    private LLMErrorClassifier $errorClassifier;

    private ?int $currentUserId = null;
    private ?string $lastCorrelationId = null;

    /** @var array<string, LLMProviderInterface> */
    private array $providerInstances = [];

    public function __construct(
        CircuitBreaker $circuitBreaker,
        LLMSettingsRepository $settings,
        LLMErrorClassifier $errorClassifier,
    ) {
        $this->circuitBreaker = $circuitBreaker;
        $this->settings = $settings;
        $this->errorClassifier = $errorClassifier;
    }

    /**
     * Установить user_id для текущего запроса.
     */
    public function setUserId(?int $userId): self
    {
        $this->currentUserId = $userId;
        return $this;
    }

    /**
     * Получить correlation_id последнего запроса.
     */
    public function getLastCorrelationId(): ?string
    {
        return $this->lastCorrelationId;
    }

    /**
     * Сгенерировать декомпозицию через LLM.
     *
     * @throws LLMUnavailableException
     */
    public function generateDecomposition(DecompositionPrompt $prompt, ?string $correlationId = null): LLMResponse
    {
        $logger = new LLMLogger($correlationId);
        $this->lastCorrelationId = $logger->getCorrelationId();
        $executionPlan = $this->buildExecutionPlan();

        $failoverChain = [];
        $jsonRetryCount = 0;
        $attemptIndex = 0;

        Log::info('LLMRouter: starting request', [
            'correlation_id' => $logger->getCorrelationId(),
            'execution_plan' => $executionPlan,
        ]);

        foreach ($executionPlan as $providerName) {
            // --- gate: circuit breaker ---
            if (!$this->circuitBreaker->isAvailable($providerName)) {
                $failoverChain[] = "{$providerName}:circuit_open";
                Log::info("LLMRouter: skip {$providerName} (circuit open)");
                continue;
            }

            // --- gate: configured ---
            $provider = $this->getProvider($providerName);
            if ($provider === null) {
                $failoverChain[] = "{$providerName}:not_configured";
                Log::warning("LLMRouter: skip {$providerName} (not configured)");
                continue;
            }

            // --- try with retry ---
            $retryCount = 0;
            $maxAttempts = self::MAX_ATTEMPTS_PER_PROVIDER;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $startTime = microtime(true);

                try {
                    $response = $provider->generateDecomposition($prompt);

                    // Success
                    $this->circuitBreaker->recordSuccess($providerName);

                    $logger->logFinal(
                        prompt: $prompt,
                        response: $response,
                        failoverChain: $failoverChain,
                        executionPlan: $executionPlan,
                        attemptIndex: $attemptIndex,
                        retryCount: $retryCount,
                        userId: $this->currentUserId,
                    );

                    return $response;

                } catch (InvalidLLMJsonException $e) {
                    $jsonRetryCount++;
                    $failoverChain[] = "{$providerName}:invalid_json";
                    $latency = (int) ((microtime(true) - $startTime) * 1000);

                    Log::warning("LLMRouter: {$providerName} invalid JSON", [
                        'correlation_id' => $logger->getCorrelationId(),
                        'retry' => $jsonRetryCount,
                    ]);

                    $logger->logAttempt(
                        prompt: $prompt,
                        providerName: $providerName,
                        errorType: LLMErrorType::INVALID_RESPONSE,
                        errorMessage: $e->getMessage(),
                        httpStatus: null,
                        attemptIndex: $attemptIndex,
                        executionPlan: $executionPlan,
                        userId: $this->currentUserId,
                        latencyMs: $latency,
                    );

                    if ($jsonRetryCount > self::MAX_JSON_RETRY_ATTEMPTS) {
                        Log::error("LLMRouter: max JSON retries reached");
                        break 2; // выход из обоих циклов
                    }

                    // JSON retry — не считаем circuit failure
                    break; // next provider

                } catch (LLMProviderException $e) {
                    $errorType = $this->errorClassifier->classify($e, $e->getHttpStatus());
                    $latency = (int) ((microtime(true) - $startTime) * 1000);
                    $failoverChain[] = "{$providerName}:{$errorType->value}";

                    Log::warning("LLMRouter: {$providerName} failed", [
                        'correlation_id' => $logger->getCorrelationId(),
                        'error_type' => $errorType->value,
                        'http_status' => $e->getHttpStatus(),
                        'attempt' => $attempt + 1,
                    ]);

                    $logger->logAttempt(
                        prompt: $prompt,
                        providerName: $providerName,
                        errorType: $errorType,
                        errorMessage: $e->getMessage(),
                        httpStatus: $e->getHttpStatus(),
                        attemptIndex: $attemptIndex,
                        executionPlan: $executionPlan,
                        userId: $this->currentUserId,
                        latencyMs: $latency,
                    );

                    // Fail-fast для auth/config
                    if (!$errorType->isFailoverAllowed()) {
                        Log::critical("LLMRouter: {$providerName} config/auth error — no failover");

                        if ($this->settings->getMode() === 'manual') {
                            $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
                                "Provider {$providerName} configuration error: {$e->getMessage()}");
                        }
                        break; // перейти к следующему провайдеру
                    }

                    $this->circuitBreaker->recordFailure($providerName, $errorType->value);

                    // Retry для retryable ошибок
                    if ($errorType->isRetryable() && $attempt + 1 < $maxAttempts) {
                        $retryCount++;
                        $delayMs = (int) (self::RETRY_BASE_DELAY_MS * (2 ** $attempt) + random_int(0, self::RETRY_JITTER_MAX_MS));
                        Log::info("LLMRouter: retrying {$providerName} (attempt " . ($attempt + 2) . ") after {$delayMs}ms");
                        usleep($delayMs * 1000);
                        continue; // повторить цикл for
                    }

                    // В manual — не делаем failover на следующий провайдер
                    if ($this->settings->getMode() === 'manual') {
                        $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
                            'AI unavailable (manual mode, no failover)');
                    }

                    break; // следующий провайдер

                } catch (\Throwable $e) {
                    $errorType = $this->errorClassifier->classify($e);
                    $latency = (int) ((microtime(true) - $startTime) * 1000);
                    $failoverChain[] = "{$providerName}:{$errorType->value}";

                    Log::error("LLMRouter: {$providerName} unexpected error", [
                        'correlation_id' => $logger->getCorrelationId(),
                        'error' => $e->getMessage(),
                    ]);

                    $logger->logAttempt(
                        prompt: $prompt,
                        providerName: $providerName,
                        errorType: $errorType,
                        errorMessage: $e->getMessage(),
                        httpStatus: null,
                        attemptIndex: $attemptIndex,
                        executionPlan: $executionPlan,
                        userId: $this->currentUserId,
                        latencyMs: $latency,
                    );

                    $this->circuitBreaker->recordFailure($providerName, $errorType->value);

                    if ($this->settings->getMode() === 'manual') {
                        $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
                            "AI unavailable: {$e->getMessage()}");
                    }

                    break; // следующий провайдер
                }
            }

            $attemptIndex++;
        }

        // Все провайдеры недоступны
        $this->throwUnavailable($prompt, $logger, $failoverChain, $executionPlan,
            'All LLM providers are unavailable');
    }

    /**
     * Построить execution plan из настроек.
     *
     * @return string[]
     */
    public function buildExecutionPlan(): array
    {
        $mode = $this->settings->getMode();
        $primary = $this->settings->getPrimaryProvider();

        if ($mode === 'manual') {
            return [$primary];
        }

        $fallbacks = $this->settings->getFallbackProviders();
        return array_values(array_unique(array_merge([$primary], $fallbacks)));
    }

    /**
     * Тестировать провайдера (ping).
     */
    public function testProvider(string $name): array
    {
        $provider = $this->getProvider($name);

        if ($provider === null) {
            return [
                'provider' => $name,
                'available' => false,
                'error' => 'Provider not configured',
            ];
        }

        $startTime = microtime(true);

        try {
            $available = $provider->isAvailable();
            $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

            return [
                'provider' => $name,
                'available' => $available,
                'latency_ms' => $latencyMs,
                'circuit_breaker' => $this->circuitBreaker->getStats($name),
                'execution_plan' => $this->buildExecutionPlan(),
            ];
        } catch (\Throwable $e) {
            return [
                'provider' => $name,
                'available' => false,
                'error' => $e->getMessage(),
                'circuit_breaker' => $this->circuitBreaker->getStats($name),
            ];
        }
    }

    /**
     * Сбросить circuit breaker для провайдера.
     */
    public function resetCircuitBreaker(string $name): void
    {
        $this->circuitBreaker->reset($name);
    }

    // -------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------

    private function getProvider(string $name): ?LLMProviderInterface
    {
        if (isset($this->providerInstances[$name])) {
            return $this->providerInstances[$name];
        }

        $providerSettings = $this->settings->getProviderSettings($name);
        $provider = ProviderRegistry::createProvider($name, $providerSettings);

        if ($provider !== null) {
            $this->providerInstances[$name] = $provider;
        }

        return $provider;
    }

    /**
     * @throws LLMUnavailableException
     * @return never
     */
    private function throwUnavailable(
        DecompositionPrompt $prompt,
        LLMLogger $logger,
        array $failoverChain,
        array $executionPlan,
        string $message,
    ): never {
        $logger->logAllFailed(
            prompt: $prompt,
            failoverChain: $failoverChain,
            executionPlan: $executionPlan,
            errorMessage: $message,
            userId: $this->currentUserId,
        );

        throw new LLMUnavailableException(
            message: $message,
            failoverChain: $failoverChain,
        );
    }
}
