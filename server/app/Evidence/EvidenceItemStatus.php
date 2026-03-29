<?php

namespace App\Evidence;

/**
 * Status for estimate evidence items within a run.
 */
final class EvidenceItemStatus
{
    public const PENDING = 'pending';
    public const COLLECTING = 'collecting';
    public const RESOLVED = 'resolved';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public static function all(): array
    {
        return [
            self::PENDING,
            self::COLLECTING,
            self::RESOLVED,
            self::FAILED,
            self::SKIPPED,
        ];
    }

    /**
     * Terminal statuses — item will not change further.
     */
    public static function terminalStatuses(): array
    {
        return [
            self::RESOLVED,
            self::FAILED,
            self::SKIPPED,
        ];
    }

    /**
     * Statuses that count as "completed" for run counter purposes.
     */
    public static function completedStatuses(): array
    {
        return [
            self::RESOLVED,
            self::SKIPPED,
        ];
    }
}
