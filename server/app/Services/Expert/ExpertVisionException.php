<?php

declare(strict_types=1);

namespace App\Services\Expert;

use RuntimeException;
use Throwable;

final class ExpertVisionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalid(): self
    {
        return new self('vision_material_invalid', 422, 'Изображение повреждено или имеет неподдерживаемый формат.');
    }

    public static function tooLarge(): self
    {
        return new self('vision_material_too_large', 413, 'Изображение превышает допустимые ограничения для AI-анализа.');
    }

    public static function tooMany(): self
    {
        return new self('vision_too_many_images', 422, 'Выбрано слишком много изображений для одного сообщения.');
    }

    public static function preparationFailed(?Throwable $previous = null): self
    {
        return new self('vision_preparation_failed', 422, 'Не удалось подготовить изображение для AI-анализа.', $previous);
    }
}
