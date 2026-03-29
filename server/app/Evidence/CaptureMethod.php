<?php

namespace App\Evidence;

/**
 * How the evidence was captured / collected.
 */
final class CaptureMethod
{
    public const AUTO_SCRAPE = 'auto_scrape';
    public const MANUAL_ENTRY = 'manual_entry';
    public const CHROME_EXTENSION = 'chrome_extension';
    public const FILE_UPLOAD = 'file_upload';
    public const API_IMPORT = 'api_import';

    public static function all(): array
    {
        return [
            self::AUTO_SCRAPE,
            self::MANUAL_ENTRY,
            self::CHROME_EXTENSION,
            self::FILE_UPLOAD,
            self::API_IMPORT,
        ];
    }
}
