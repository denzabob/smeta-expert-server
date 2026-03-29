<?php

namespace App\Evidence;

/**
 * Verification status for evidence records.
 */
final class VerificationStatus
{
    public const PENDING = 'pending';
    public const AUTO_VERIFIED = 'auto_verified';
    public const MANUAL_VERIFIED = 'manual_verified';
    public const REJECTED = 'rejected';
    public const STALE = 'stale';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::AUTO_VERIFIED,
            self::MANUAL_VERIFIED,
            self::REJECTED,
            self::STALE,
        ];
    }
}
