<?php

namespace App\Services\LLM\DTO;

final readonly class LLMParsedFile
{
    /**
     * @param list<LLMParsedFileImage> $images
     */
    public function __construct(
        public string $sha256,
        public string $name,
        public string $text,
        public array $images,
    ) {}
}
