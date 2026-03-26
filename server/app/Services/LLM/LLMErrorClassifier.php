<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Services\LLM\Enums\LLMErrorType;
use App\Services\LLM\Exceptions\LLMProviderException;
use App\Services\LLM\Exceptions\InvalidLLMJsonException;
use Illuminate\Http\Client\ConnectionException;

/**
 * Классификатор ошибок LLM провайдеров.
 *
 * Единственное место, где определяется тип ошибки по исключению.
 */
class LLMErrorClassifier
{
    /**
     * Классифицировать исключение в LLMErrorType.
     *
     * @param \Throwable $e          Исключение
     * @param int|null   $httpCode   HTTP статус-код (если известен)
     * @param string|null $responseBody Тело ответа (для уточнения 429/5xx)
     */
    public function classify(\Throwable $e, ?int $httpCode = null, ?string $responseBody = null): LLMErrorType
    {
        if ($e instanceof InvalidLLMJsonException) {
            return LLMErrorType::INVALID_RESPONSE;
        }

        if ($e instanceof LLMProviderException) {
            $httpCode = $httpCode ?? $e->getHttpStatus();
            return $this->classifyProviderException($e, $httpCode, $responseBody);
        }

        if ($e instanceof ConnectionException) {
            if (str_contains($e->getMessage(), 'timed out')) {
                return LLMErrorType::TIMEOUT;
            }
            return LLMErrorType::NETWORK;
        }

        // Classify by httpCode if available
        if ($httpCode !== null) {
            return $this->classifyByHttpCode($httpCode);
        }

        return LLMErrorType::UNKNOWN;
    }

    /**
     * Классифицировать LLMProviderException по errorType string → enum.
     */
    private function classifyProviderException(LLMProviderException $e, ?int $httpCode, ?string $responseBody): LLMErrorType
    {
        // First try by explicit errorType string
        $byType = match ($e->getErrorType()) {
            'auth' => LLMErrorType::AUTH,
            'config' => LLMErrorType::CONFIG,
            'timeout' => LLMErrorType::TIMEOUT,
            'http_429' => LLMErrorType::RATE_LIMIT,
            'http_5xx' => LLMErrorType::SERVER_ERROR,
            'network' => LLMErrorType::NETWORK,
            'invalid_json' => LLMErrorType::INVALID_RESPONSE,
            default => null,
        };

        if ($byType !== null) {
            return $byType;
        }

        // Refine by HTTP code
        if ($httpCode !== null) {
            return $this->classifyByHttpCode($httpCode);
        }

        // Check response body for auth-related patterns
        if ($responseBody !== null && preg_match('/invalid.?api.?key|unauthorized|authentication/i', $responseBody)) {
            return LLMErrorType::AUTH;
        }

        return LLMErrorType::UNKNOWN;
    }

    /**
     * Классифицировать по HTTP status code.
     */
    private function classifyByHttpCode(int $httpCode): LLMErrorType
    {
        return match (true) {
            $httpCode === 401, $httpCode === 403 => LLMErrorType::AUTH,
            $httpCode === 429 => LLMErrorType::RATE_LIMIT,
            $httpCode === 408 => LLMErrorType::TIMEOUT,
            $httpCode >= 500 => LLMErrorType::SERVER_ERROR,
            default => LLMErrorType::UNKNOWN,
        };
    }
}
