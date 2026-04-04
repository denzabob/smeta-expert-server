<?php

namespace App\Evidence;

/**
 * How an evidence item was resolved (auto, manual, etc.).
 */
final class ResolutionType
{
    public const AUTO = 'auto';
    public const AUTO_FRESH = 'auto_fresh';
    public const MANUAL = 'manual';
    public const CHROME = 'chrome';
    public const SKIPPED = 'skipped';

    public static function all(): array
    {
        return [
            self::AUTO,
            self::AUTO_FRESH,
            self::MANUAL,
            self::CHROME,
            self::SKIPPED,
        ];
    }
}
