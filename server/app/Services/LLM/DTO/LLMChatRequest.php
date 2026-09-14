<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMChatRequest
{
    /** @param list<LLMChatMessage|array{role:string,content:mixed}> $messages */
    public function __construct(
        public readonly string $systemMessage,
        public readonly array $messages,
        public readonly array $materialContext = [],
    ) {}

    public function hasImages(): bool
    {
        foreach ($this->contentBlocks() as $content) if ($content instanceof LLMImageContent) return true;
        return false;
    }

    public function hasFiles(): bool
    {
        foreach ($this->contentBlocks() as $content) if ($content instanceof LLMFileContent) return true;
        return false;
    }

    public function hasPdfOcrFiles(): bool
    {
        foreach ($this->contentBlocks() as $content) if ($content instanceof LLMFileContent && $content->processingIntent === \App\Services\LLM\Enums\LLMFileProcessingIntent::PDF_OCR) return true;
        return false;
    }

    /** @return list<mixed> */
    private function contentBlocks(): array
    {
        $blocks = [];
        foreach ($this->messages as $message) {
            if ($message instanceof LLMChatMessage) $blocks = [...$blocks, ...$message->content];
            elseif (is_array($message) && isset($message['content']) && is_array($message['content'])) $blocks = [...$blocks, ...$message['content']];
        }
        return $blocks;
    }
}