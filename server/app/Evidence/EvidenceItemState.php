<?php

namespace App\Evidence;

final class EvidenceItemState
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const FAILED = 'failed';
    public const MANUAL_REQUIRED = 'manual_required';
    public const MANUAL_VERIFIED = 'manual_verified';
    public const AUTO_VERIFIED = 'auto_verified';
    public const FINALIZED = 'finalized';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::RUNNING,
            self::FAILED,
            self::MANUAL_REQUIRED,
            self::MANUAL_VERIFIED,
            self::AUTO_VERIFIED,
            self::FINALIZED,
        ];
    }
}

