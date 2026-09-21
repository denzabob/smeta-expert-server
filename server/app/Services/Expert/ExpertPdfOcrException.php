<?php
declare(strict_types=1);
namespace App\Services\Expert;
use RuntimeException;
final class ExpertPdfOcrException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status=422){parent::__construct($message);}
    public static function disabled(): self{return new self('pdf_ocr_disabled','Обработка PDF временно отключена.',422);}
    public static function notSupported(): self{return new self('pdf_ocr_not_supported','Текущая модель AI не поддерживает обработку PDF.',422);}
    public static function tooLarge(): self{return new self('pdf_ocr_too_large','Документ превышает допустимый объём обработки.',413);}
    public static function processingTooLarge(): self{return new self('pdf_processing_too_large','Документ превышает допустимый объём обработки.',413);}
    public static function tooManyPages(): self{return new self('pdf_ocr_too_many_pages','Документ превышает допустимое число страниц для обработки.',422);}
    public static function failed(): self{return new self('pdf_ocr_failed','Не удалось обработать PDF.',502);}
    public static function cacheInvalid(): self{return new self('pdf_ocr_cache_invalid','Кеш обработки PDF повреждён или не прошёл проверку.',422);}
}
