<?php

declare(strict_types=1);

namespace App\Services\Expert;

use RuntimeException;

class ExpertMaterialContextException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly ?string $reason = null,
    ) {
        parent::__construct($message);
    }

    public static function unsupported(): self
    {
        return new self(
            'material_context_unsupported',
            'Выбранный тип файла пока нельзя использовать как контекст AI.',
        );
    }

    public static function temporarilyDisabled(): self
    {
        return new self(
            'material_context_temporarily_disabled',
            'Использование XLSX как контекста AI временно отключено из соображений безопасности.',
        );
    }

    public static function extractionFailed(): self
    {
        return new self(
            'material_context_extraction_failed',
            'Не удалось извлечь текст из выбранного материала. Для PDF поддерживаются только файлы с текстовым слоем.',
        );
    }

    public static function pdfWithoutUsableText(int $pageCount): self
    {
        return new self('pdf_no_usable_text', 'В PDF не найден пригодный текстовый слой.', 422, 'pdf_no_usable_text:'.max(0, $pageCount));
    }

    public static function pdfLocalExtractionFailed(int $pageCount): self
    {
        return new self('pdf_local_extraction_failed', 'Локальное извлечение текста из PDF недоступно.', 422, 'pdf_local_extraction_failed:'.max(0, $pageCount));
    }

    public static function pdfEncrypted(): self
    {
        return new self('pdf_encrypted', 'Зашифрованный PDF нельзя использовать в Chat.', 422);
    }

    public static function pdfMalformed(): self
    {
        return new self('pdf_malformed', 'Структура PDF повреждена или некорректна.', 422);
    }

    public static function tooLarge(): self
    {
        return new self(
            'material_context_too_large',
            'Выбранные материалы превышают допустимые ограничения для контекста AI.',
        );
    }

    public static function pdfSourceTooLarge(): self
    {
        return new self(
            'pdf_source_too_large',
            'Исходный PDF превышает допустимый размер для обработки.',
            413,
        );
    }

    public static function pdfTextTooLarge(int $pageCount, int $extractedChars): self
    {
        return new self(
            'material_context_too_large',
            'Текст PDF превышает локальный порог и требует обработки как исходный документ.',
            422,
            'pdf_text_too_large:'.max(0, $pageCount).':'.max(0, $extractedChars),
        );
    }

    public static function notFound(): self
    {
        return new self(
            'material_context_not_found',
            'Один или несколько выбранных материалов недоступны.',
            404,
        );
    }

    public static function originalUnavailable(): self
    {
        return new self('original_material_unavailable', 'Исходный материал сообщения удалён и больше недоступен.', 422);
    }

    public static function multiDocumentPipelineRequired(?string $requestedMode = null): self
    {
        $message = $requestedMode === ExpertModeResolution::FAST
            ? 'Для полного анализа выбранного набора требуется многодокументная обработка, которая пока недоступна в выбранном режиме.'
            : 'Для полного анализа этого набора материалов требуется отдельный многодокументный режим.';

        return new self('multi_document_pipeline_required', $message, 422);
    }

    public static function multiDocumentRequired(?string $requestedMode = null): self
    {
        $message = $requestedMode === ExpertModeResolution::FAST
            ? 'Для полного анализа выбранного набора требуется многодокументная обработка, которая пока недоступна в выбранном режиме.'
            : 'Для обработки этого набора материалов требуется многодокументный режим.';

        return new self('multi_document_required', $message, 422);
    }

    public static function retrievalPipelineRequired(): self
    {
        return new self('retrieval_pipeline_required', 'Для поиска по всему набору материалов требуется отдельный поисковый режим.', 422);
    }

    public static function ambiguousActiveMaterials(): self
    {
        return new self('active_material_ambiguous', 'Уточните название материала или приложите его к сообщению.', 422);
    }
}
