<?php

namespace App\Evidence;

final class EvidenceStageStatus
{
    public const OK = 'ok';
    public const RETRYABLE_ERROR = 'retryable_error';
    public const HARD_FAIL = 'hard_fail';
    public const SKIPPED = 'skipped';

    public static function all(): array
    {
        return [
            self::OK,
            self::RETRYABLE_ERROR,
            self::HARD_FAIL,
            self::SKIPPED,
        ];
    }
}

