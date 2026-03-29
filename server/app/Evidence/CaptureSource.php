<?php

namespace App\Evidence;

final class CaptureSource
{
    public const AUTO = 'auto';
    public const MANUAL = 'manual';
    public const CHROME_EXT = 'chrome_ext';
    public const INTERNAL = 'internal';

    public static function all(): array
    {
        return [
            self::AUTO,
            self::MANUAL,
            self::CHROME_EXT,
            self::INTERNAL,
        ];
    }
}
