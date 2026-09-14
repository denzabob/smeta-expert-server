<?php

namespace App\Services\LLM\DTO;

use App\Services\LLM\Enums\LLMFileProcessingIntent;

final readonly class LLMFileContent
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $bytes,
        public string $sha256,
        public LLMFileProcessingIntent $processingIntent,
    ) {}
}
