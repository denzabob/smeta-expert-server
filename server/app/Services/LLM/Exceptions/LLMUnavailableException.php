<?php

declare(strict_types=1);

namespace App\Services\LLM\Exceptions;

/**
 * Исключение: все LLM провайдеры недоступны
 */
class LLMUnavailableException extends LLMException
{
    protected string $errorType = 'unavailable';
    protected array $failoverChain = [];

    public function __construct(
        string $message = 'All LLM providers are unavailable',
        array $failoverChain = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->failoverChain = $failoverChain;
    }

    public function getFailoverChain(): array
    {
        return $this->failoverChain;
    }
}
