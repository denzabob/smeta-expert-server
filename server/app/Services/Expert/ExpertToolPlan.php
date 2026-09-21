<?php

declare(strict_types=1);

namespace App\Services\Expert;

final readonly class ExpertToolPlan
{
    public const LOCAL_TEXT_PARSE = 'local_text_parse';
    public const PDF_TEXT_PARSE = 'pdf_text_parse';
    public const PDF_OCR = 'pdf_ocr';
    public const VISION = 'vision';
    public const NORMATIVE_RAG = 'normative_rag';
    public const PROJECT_RAG = 'project_rag';
    public const WEB_SEARCH = 'web_search';
    public const DEEP_RESEARCH = 'deep_research';

    /** @param list<string> $tools @param list<string> $requiredCapabilities */
    public function __construct(
        public array $tools,
        public array $requiredCapabilities,
    ) {}

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'tools' => $this->tools,
            'required_capabilities' => $this->requiredCapabilities,
        ];
    }
}
