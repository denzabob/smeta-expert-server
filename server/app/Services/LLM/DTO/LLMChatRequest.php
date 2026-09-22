<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMChatRequest
{
    /** @param list<LLMChatMessage|array{role:string,content:mixed}> $messages @param array<string, mixed> $parameters */
    public function __construct(
        public readonly string $systemMessage,
        public readonly array $messages,
        public readonly array $materialContext = [],
        public readonly bool $materialContextEmbedded = false,
        public readonly array $parameters = [],
    ) {}

    /** @param array<string, mixed> $parameters */
    public function withParameters(array $parameters): self
    {
        return new self(
            $this->systemMessage,
            $this->messages,
            $this->materialContext,
            $this->materialContextEmbedded,
            [...$this->parameters, ...$parameters],
        );
    }

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

    public function hasPdfTextParseFiles(): bool
    {
        foreach ($this->contentBlocks() as $content) if ($content instanceof LLMFileContent && $content->processingIntent === \App\Services\LLM\Enums\LLMFileProcessingIntent::PDF_TEXT_PARSE) return true;
        return false;
    }

    /** @return list<string> */
    public function pdfProcessingIntents(): array
    {
        $intents = [];
        foreach ($this->contentBlocks() as $content) {
            if ($content instanceof LLMFileContent) {
                $intents[] = $content->processingIntent->value;
            }
        }

        return array_values(array_unique($intents));
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
