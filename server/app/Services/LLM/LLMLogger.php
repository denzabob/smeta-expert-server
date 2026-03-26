<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Models\AiLog;
use App\Services\LLM\DTO\DecompositionPrompt;
use App\Services\LLM\DTO\LLMResponse;
use App\Services\LLM\Enums\LLMErrorType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Централизованный логгер LLM запросов.
 *
 * Обеспечивает:
 * — correlation_id для связывания попыток в одной цепочке
 * — запись промежуточных попыток (logAttempt)
 * — запись финального результата (logFinal)
 * — execution plan + provider state snapshot
 */
class LLMLogger
{
    private string $correlationId;

    public function __construct(?string $correlationId = null)
    {
        $this->correlationId = $correlationId ?? (string) Str::uuid();
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Логировать одну неудачную попытку (промежуточный провайдер).
     */
    public function logAttempt(
        DecompositionPrompt $prompt,
        string $providerName,
        LLMErrorType $errorType,
        string $errorMessage,
        ?int $httpStatus,
        int $attemptIndex,
        array $executionPlan,
        ?int $userId = null,
        int $latencyMs = 0,
    ): void {
        try {
            AiLog::create([
                'user_id' => $userId,
                'input_hash' => $prompt->inputHash,
                'model_name' => 'unknown',
                'provider_name' => $providerName,
                'latency_ms' => $latencyMs,
                'is_successful' => false,
                'fallback_used' => $attemptIndex > 0,
                'error_message' => mb_substr($errorMessage, 0, 2000),
                'error_type' => $errorType->value,
                'http_status' => $httpStatus,
                'metadata' => [
                    'correlation_id' => $this->correlationId,
                    'execution_plan' => $executionPlan,
                    'attempt_index' => $attemptIndex,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLMLogger: failed to log attempt', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Логировать финальный успешный запрос.
     */
    public function logFinal(
        DecompositionPrompt $prompt,
        LLMResponse $response,
        array $failoverChain,
        array $executionPlan,
        int $attemptIndex,
        int $retryCount,
        ?int $userId = null,
    ): void {
        try {
            AiLog::create([
                'user_id' => $userId,
                'input_hash' => $prompt->inputHash,
                'model_name' => $response->model,
                'provider_name' => $response->provider,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'cost_usd' => $response->costUsd,
                'latency_ms' => $response->latencyMs,
                'is_successful' => true,
                'fallback_used' => $attemptIndex > 0,
                'failover_chain' => $failoverChain,
                'metadata' => [
                    'correlation_id' => $this->correlationId,
                    'execution_plan' => $executionPlan,
                    'attempt_index' => $attemptIndex,
                    'retry_count' => $retryCount,
                    'used_json_mode' => $response->usedJsonMode,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLMLogger: failed to log final', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Логировать полный провал (все провайдеры недоступны).
     */
    public function logAllFailed(
        DecompositionPrompt $prompt,
        array $failoverChain,
        array $executionPlan,
        string $errorMessage,
        ?int $userId = null,
    ): void {
        try {
            AiLog::create([
                'user_id' => $userId,
                'input_hash' => $prompt->inputHash,
                'model_name' => 'none',
                'provider_name' => null,
                'latency_ms' => 0,
                'is_successful' => false,
                'fallback_used' => count($failoverChain) > 0,
                'failover_chain' => $failoverChain,
                'error_message' => mb_substr($errorMessage, 0, 2000),
                'error_type' => 'unavailable',
                'metadata' => [
                    'correlation_id' => $this->correlationId,
                    'execution_plan' => $executionPlan,
                    'degraded' => true,
                    'reason' => 'all providers unavailable',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('LLMLogger: failed to log all-failed', ['error' => $e->getMessage()]);
        }
    }
}
