<?php

declare(strict_types=1);

namespace App\Services\LLM\Exceptions;

use App\Services\LLM\Enums\LLMErrorType;

/**
 * All configured providers failed to complete a text-chat request.
 */
final class LLMChatUnavailableException extends LLMUnavailableException
{
    public function __construct(
        string $message = 'All LLM providers are unavailable for chat',
        array $failoverChain = [],
        private readonly ?LLMErrorType $lastErrorType = null,
    ) {
        parent::__construct($message, $failoverChain);
    }

    public function lastErrorType(): ?LLMErrorType
    {
        return $this->lastErrorType;
    }

    public function isTimeout(): bool
    {
        return $this->lastErrorType === LLMErrorType::TIMEOUT;
    }
}
