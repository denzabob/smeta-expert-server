<?php

declare(strict_types=1);

namespace App\Services\LLM\Enums;

enum LLMCapability: string
{
    case TEXT_INPUT = 'text_input';
    case IMAGE_INPUT = 'image_input';
    case FILE_INPUT = 'file_input';
    case PDF_OCR = 'pdf_ocr';
    case STREAMING = 'streaming';
    case REASONING = 'reasoning';
    case TOOLS = 'tools';
    case STRUCTURED_OUTPUT = 'structured_output';
}
