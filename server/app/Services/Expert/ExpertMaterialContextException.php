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
        return new self('material_context_extraction_failed', 'В PDF не найден пригодный текстовый слой.', 422, 'pdf_no_usable_text:' . max(0, $pageCount));
    }

    public static function tooLarge(): self
    {
        return new self(
            'material_context_too_large',
            'Выбранные материалы превышают допустимые ограничения для контекста AI.',
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
}
