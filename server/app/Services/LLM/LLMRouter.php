<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Models\AiLog;
use App\Services\LLM\Contracts\LLMProviderInterface;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Exceptions\InvalidLLMJsonException;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Exceptions\LLMUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * Роутер LLM запросов с поддержкой failover
 * 
 * Функции:
 * - выбрать провайдеров в порядке: primary → fallback providers
 * - при технической ошибке → failover на следующий провайдер
 * - invalid JSON → failover допускается (но ограничен 1-2 попытками)
 * - если все провайдеры упали → LLMUnavailableException
 * 
 * Режимы:
 * - manual: использовать только primary, без failover
 * - auto: включен failover
 */
class LLMRouter
{
    private const MAX_JSON_RETRY_ATTEMPTS = 1;

    private CircuitBreaker $circuitBreaker;
    private LLMSettingsRepository $settings;
    
    /** @var int|null Текущий user_id для логирования */
    private ?int $currentUserId = null;

    /** @var array<string, LLMProviderInterface> */
    private array $providerInstances = [];

    public function __construct(
        CircuitBreaker $circuitBreaker,
        LLMSettingsRepository $settings
    ) {
        $this->circuitBreaker = $circuitBreaker;
        $this->settings = $settings;
    }

    /**
     * Установить user_id для текущего запроса
     */
    public function setUserId(?int $userId): self
    {
        $this->currentUserId = $userId;
        return $this;
    }

    /**
     * Сгенерировать декомпозицию через LLM
     * 
     * @throws LLMUnavailableException
     */
    public function generateDecomposition(DecompositionPrompt $prompt): LLMResponse
    {
        $mode = $this->settings->getMode();
        $primaryName = $this->settings->getPrimaryProvider();
        $fallbackNames = $this->settings->getFallbackProviders();

        $failoverChain = [];
        $jsonRetryCount = 0;

        // Собираем список провайдеров для попыток
        $providerNames = [$primaryName];
        if ($mode === 'auto') {
            $providerNames = array_merge($providerNames, $fallbackNames);
        }

        foreach ($providerNames as $providerName) {
            // Проверяем circuit breaker
            if (!$this->circuitBreaker->isAvailable($providerName)) {
                $failoverChain[] = "{$providerName}:circuit_open";
                Log::info("LLMRouter: skipping {$providerName} (circuit breaker open)");
                continue;
            }

            $provider = $this->getProvider($providerName);
            if ($provider === null) {
                $failoverChain[] = "{$providerName}:not_configured";
                Log::warning("LLMRouter: provider {$providerName} not configured");
                continue;
            }

            try {
                $response = $provider->generateDecomposition($prompt);

                // Успех!
                $this->circuitBreaker->recordSuccess($providerName);

                // Логируем
                $this->logRequest(
                    prompt: $prompt,
                    response: $response,
                    failoverChain: $failoverChain,
                    isSuccessful: true
                );

                return $response;

            } catch (InvalidLLMJsonException $e) {
                // JSON не парсится — допускается ограниченный failover
                $jsonRetryCount++;
                $failoverChain[] = "{$providerName}:invalid_json";

                Log::warning("LLMRouter: {$providerName} returned invalid JSON", [
                    'error' => $e->getMessage(),
                    'retry_count' => $jsonRetryCount,
                ]);

                if ($jsonRetryCount > self::MAX_JSON_RETRY_ATTEMPTS) {
                    Log::error("LLMRouter: max JSON retry attempts reached");
                    break;
                }

                // Не регистрируем как failure в circuit breaker
                // (это может быть временная проблема модели)

            } catch (LLMProviderException $e) {
                $failoverChain[] = "{$providerName}:{$e->getErrorType()}";

                Log::warning("LLMRouter: {$providerName} failed", [
                    'error_type' => $e->getErrorType(),
                    'http_status' => $e->getHttpStatus(),
                    'message' => $e->getMessage(),
                ]);

                // Логируем неуспешный запрос
                $this->logFailedRequest(
                    prompt: $prompt,
                    providerName: $providerName,
                    errorType: $e->getErrorType(),
                    httpStatus: $e->getHttpStatus(),
                    errorMessage: $e->getMessage()
                );

                // Регистрируем в circuit breaker если failover допускается
                if ($e->isFailoverAllowed()) {
                    $this->circuitBreaker->recordFailure($providerName, $e->getErrorType());
                } else {
                    // Auth/config ошибки — критичные, не failover
                    Log::critical("LLMRouter: {$providerName} config/auth error - no failover", [
                        'error' => $e->getMessage(),
                    ]);

                    if ($mode === 'manual') {
                        throw new LLMUnavailableException(
                            message: "Provider {$providerName} configuration error: {$e->getMessage()}",
                            failoverChain: $failoverChain
                        );
                    }
                }

                // В manual режиме не делаем failover
                if ($mode === 'manual') {
                    throw new LLMUnavailableException(
                        message: 'AI unavailable (manual mode, no failover)',
                        failoverChain: $failoverChain
                    );
                }

            } catch (\Throwable $e) {
                $failoverChain[] = "{$providerName}:unknown";

                Log::error("LLMRouter: {$providerName} unexpected error", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->circuitBreaker->recordFailure($providerName, 'unknown');

                if ($mode === 'manual') {
                    throw new LLMUnavailableException(
                        message: "AI unavailable: {$e->getMessage()}",
                        failoverChain: $failoverChain
                    );
                }
            }
        }

        // Все провайдеры недоступны
        throw new LLMUnavailableException(
            message: 'All LLM providers are unavailable',
            failoverChain: $failoverChain
        );
    }

    /**
     * Получить экземпляр провайдера
     */
    private function getProvider(string $name): ?LLMProviderInterface
    {
        if (isset($this->providerInstances[$name])) {
            return $this->providerInstances[$name];
        }

        $providerSettings = $this->settings->getProviderSettings($name);

        // Используем ProviderRegistry для создания провайдера
        $provider = ProviderRegistry::createProvider($name, $providerSettings);

        if ($provider !== null) {
            $this->providerInstances[$name] = $provider;
        }

        return $provider;
    }

    /**
     * Логировать успешный запрос
     */
    private function logRequest(
        DecompositionPrompt $prompt,
        LLMResponse $response,
        array $failoverChain,
        bool $isSuccessful
    ): void {
        try {
            AiLog::create([
                'user_id' => $this->currentUserId,
                'input_hash' => $prompt->inputHash,
                'model_name' => $response->model,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'cost_usd' => $response->costUsd,
                'latency_ms' => $response->latencyMs,
                'is_successful' => $isSuccessful,
                'provider_name' => $response->provider,
                'fallback_used' => count($failoverChain) > 0,
                'failover_chain' => $failoverChain,
                'metadata' => [
                    'used_json_mode' => $response->usedJsonMode,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLMRouter: failed to log request', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Логировать неуспешный запрос
     */
    private function logFailedRequest(
        DecompositionPrompt $prompt,
        string $providerName,
        string $errorType,
        ?int $httpStatus,
        string $errorMessage
    ): void {
        try {
            AiLog::create([
                'user_id' => $this->currentUserId,
                'input_hash' => $prompt->inputHash,
                'model_name' => 'unknown',
                'latency_ms' => 0,
                'is_successful' => false,
                'error_message' => $errorMessage,
                'provider_name' => $providerName,
                'fallback_used' => false,
                'error_type' => $errorType,
                'http_status' => $httpStatus,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLMRouter: failed to log failed request', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Тестировать провайдера (ping)
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
     * Сбросить circuit breaker для провайдера
     */
    public function resetCircuitBreaker(string $name): void
    {
        $this->circuitBreaker->reset($name);
    }
}
