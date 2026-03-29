<?php

namespace App\Evidence;

/**
 * Source type for evidence records — where the price data originally came from.
 */
final class SourceType
{
    public const SUPPLIER_WEBSITE = 'supplier_website';
    public const MANUAL_INPUT = 'manual_input';
    public const INTERNAL_CALC = 'internal_calc';
    public const DOCUMENT = 'document';
    public const CHROME_CAPTURE = 'chrome_capture';

    public static function all(): array
    {
        return [
            self::SUPPLIER_WEBSITE,
            self::MANUAL_INPUT,
            self::INTERNAL_CALC,
            self::DOCUMENT,
            self::CHROME_CAPTURE,
        ];
    }
}
