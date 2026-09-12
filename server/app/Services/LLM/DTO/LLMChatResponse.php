<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

/**
 * Plain-text response from an LLM chat completion.
 */
final class LLMChatResponse
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly int $latencyMs,
        public readonly ?int $promptTokens = null,
        public readonly ?int $completionTokens = null,
        public readonly ?float $costUsd = null,
    ) {
    }
}
