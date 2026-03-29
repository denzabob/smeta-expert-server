<?php

namespace App\Evidence;

/**
 * Status for estimate evidence runs.
 */
final class EvidenceRunStatus
{
    public const PENDING = 'pending';
    public const IN_PROGRESS = 'in_progress';
    public const READY = 'ready';
    public const FINALIZED = 'finalized';
    public const FAILED = 'failed';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::IN_PROGRESS,
            self::READY,
            self::FINALIZED,
            self::FAILED,
        ];
    }

    /**
     * Statuses from which a run can be finalized.
     * Only READY is valid for finalization.
     */
    public static function finalizableStatuses(): array
    {
        return [self::READY];
    }

    /**
     * Terminal statuses — run will not change further.
     */
    public static function terminalStatuses(): array
    {
        return [self::FINALIZED, self::FAILED];
    }
}
