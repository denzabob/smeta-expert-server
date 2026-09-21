<?php

namespace App\Services\LLM\Enums;

enum LLMFileProcessingIntent: string
{
    case PDF_TEXT_PARSE = 'pdf_text_parse';
    case PDF_OCR = 'pdf_ocr';
}
