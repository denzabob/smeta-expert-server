<?php

declare(strict_types=1);

namespace App\Services\LLM\DTO;

final class LLMStreamEvent
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<LLMParsedFile> $parsedFiles
     */
    private function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly array $metadata = [],
        public readonly array $parsedFiles = [],
        public readonly bool $isFinal = false,
    ) {}

    public static function delta(string $text): self
    {
        return new self('delta', $text);
    }

    /** @param array<string, mixed> $metadata @param list<LLMParsedFile> $parsedFiles */
    public static function done(array $metadata = [], array $parsedFiles = []): self
    {
        return new self('done', '', $metadata, $parsedFiles);
    }

    /** A provider must create this only from an explicit safe-summary field. */
    public static function reasoningSummary(string $text, bool $isFinal = false): self
    {
        return new self('reasoning_summary', $text, [], [], $isFinal);
    }

    public static function heartbeat(): self
    {
        return new self('heartbeat');
    }
}
