<?php

namespace App\Services\Expert;

use App\Services\LLM\Enums\LLMFileProcessingIntent;

final readonly class ExpertPdfOcrCandidate
{
    public function __construct(
        public string $projectPublicId,
        public string $materialPublicId,
        public string $name,
        public string $mimeType,
        public string $bytes,
        public string $sha256,
        public int $pageCount,
        public LLMFileProcessingIntent $processingIntent = LLMFileProcessingIntent::PDF_OCR,
        public int $extractedChars = 0,
    ) {}

    public function processingStrategy(): string
    {
        return $this->processingIntent === LLMFileProcessingIntent::PDF_TEXT_PARSE
            ? 'provider_pdf_text'
            : 'provider_pdf_ocr';
    }
}
