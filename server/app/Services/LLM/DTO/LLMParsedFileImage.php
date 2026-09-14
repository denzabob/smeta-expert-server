<?php

namespace App\Services\LLM\DTO;

final readonly class LLMParsedFileImage
{
    public function __construct(
        public string $mimeType,
        public string $bytes,
    ) {}
}
