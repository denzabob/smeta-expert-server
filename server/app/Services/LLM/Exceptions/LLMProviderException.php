<?php

declare(strict_types=1);

namespace App\Services\LLM\Exceptions;

/**
 * Исключение провайдера LLM
 * 
 * Используется для технических ошибок провайдера (timeout, 5xx, network)
 */
class LLMProviderException extends LLMException
{
    protected ?int $httpStatus = null;
    protected string $provider;

    public function __construct(
        string $message,
        string $provider,
        string $errorType = 'unknown',
        ?int $httpStatus = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->provider = $provider;
        $this->errorType = $errorType;
        $this->httpStatus = $httpStatus;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Определить, допускается ли failover для этой ошибки
     */
    public function isFailoverAllowed(): bool
    {
        // Auth errors — конфигурационная ошибка, failover не поможет
        if (in_array($this->errorType, ['auth', 'config'])) {
            return false;
        }

        return true;
    }

    /**
     * Создать исключение для timeout
     */
    public static function timeout(string $provider, int $timeoutSeconds): self
    {
        return new self(
            message: "Provider {$provider} timed out after {$timeoutSeconds}s",
            provider: $provider,
            errorType: 'timeout'
        );
    }

    /**
     * Создать исключение для network error
     */
    public static function networkError(string $provider, string $details): self
    {
        return new self(
            message: "Provider {$provider} network error: {$details}",
            provider: $provider,
            errorType: 'network'
        );
    }

    /**
     * Создать исключение для HTTP ошибки
     */
    public static function httpError(string $provider, int $status, string $body = ''): self
    {
        $errorType = match (true) {
            $status === 429 => 'http_429',
            $status >= 500 => 'http_5xx',
            in_array($status, [401, 403]) => 'auth',
            default => 'http_error',
        };

        return new self(
            message: "Provider {$provider} HTTP {$status}: {$body}",
            provider: $provider,
            errorType: $errorType,
            httpStatus: $status
        );
    }

    /**
     * Создать исключение для ошибки конфигурации
     */
    public static function configError(string $provider, string $details): self
    {
        return new self(
            message: "Provider {$provider} config error: {$details}",
            provider: $provider,
            errorType: 'config'
        );
    }
}
