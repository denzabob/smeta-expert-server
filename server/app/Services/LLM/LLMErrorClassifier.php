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
     */
    public function classify(\Throwable $e): LLMErrorType
    {
        if ($e instanceof InvalidLLMJsonException) {
            return LLMErrorType::INVALID_RESPONSE;
        }

        if ($e instanceof LLMProviderException) {
            return $this->classifyProviderException($e);
        }

        if ($e instanceof ConnectionException) {
            if (str_contains($e->getMessage(), 'timed out')) {
                return LLMErrorType::TIMEOUT;
            }
            return LLMErrorType::NETWORK;
        }

        return LLMErrorType::UNKNOWN;
    }

    /**
     * Классифицировать LLMProviderException по errorType string → enum.
     */
    private function classifyProviderException(LLMProviderException $e): LLMErrorType
    {
        return match ($e->getErrorType()) {
            'auth' => LLMErrorType::AUTH,
            'config' => LLMErrorType::CONFIG,
            'timeout' => LLMErrorType::TIMEOUT,
            'http_429' => LLMErrorType::RATE_LIMIT,
            'http_5xx' => LLMErrorType::SERVER_ERROR,
            'network' => LLMErrorType::NETWORK,
            'invalid_json' => LLMErrorType::INVALID_RESPONSE,
            default => LLMErrorType::UNKNOWN,
        };
    }
}
