<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

use InvalidArgumentException;

final class LLMChatMessage
{
    /**
     * @param  'user'|'assistant'  $role
     * @param  non-empty-list<LLMTextContent|LLMImageContent|LLMFileContent>  $content
     */
    public function __construct(
        public readonly string $role,
        public readonly array $content,
    ) {
        if (! in_array($role, ['user', 'assistant'], true)) {
            throw new InvalidArgumentException('Unsupported LLM chat role.');
        }

        if ($content === []) {
            throw new InvalidArgumentException('LLM chat message content cannot be empty.');
        }
    }

    /** @param 'user'|'assistant' $role */
    public static function text(string $role, string $text): self
    {
        return new self($role, [new LLMTextContent($text)]);
    }
}
