<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

/**
 * Text-only conversation payload for an LLM provider.
 *
 * @phpstan-param list<array{role: 'user'|'assistant', content: string}> $messages
 */
final class LLMChatRequest
{
    /**
     * @param list<array{role: 'user'|'assistant', content: string}> $messages
     */
    public function __construct(
        public readonly string $systemMessage,
        public readonly array $messages,
    ) {
    }

    /**
     * @return list<array{role: 'system'|'user'|'assistant', content: string}>
     */
    public function toProviderMessages(): array
    {
        return [
            ['role' => 'system', 'content' => $this->systemMessage],
            ...$this->messages,
        ];
    }
}
