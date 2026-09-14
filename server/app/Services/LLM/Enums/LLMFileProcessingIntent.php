<?php

namespace App\Services\LLM\Enums;

enum LLMFileProcessingIntent: string
{
    case PDF_OCR = 'pdf_ocr';
}
