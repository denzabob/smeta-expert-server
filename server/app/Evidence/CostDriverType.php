<?php

namespace App\Evidence;

final class CostDriverType
{
    public const PLATE = 'plate';
    public const EDGE = 'edge';
    public const FACADE = 'facade';
    public const FITTING = 'fitting';
    public const OPERATION = 'operation';
    public const LABOR_WORK = 'labor_work';
    public const EXPENSE = 'expense';

    public static function all(): array
    {
        return [
            self::PLATE,
            self::EDGE,
            self::FACADE,
            self::FITTING,
            self::OPERATION,
            self::LABOR_WORK,
            self::EXPENSE,
        ];
    }

    /**
     * Cost driver types that reference a web URL for price evidence.
     */
    public static function requiresUrl(): array
    {
        return [
            self::PLATE,
            self::EDGE,
            self::FITTING,
        ];
    }

    /**
     * Cost driver types that require a screenshot as evidence.
     */
    public static function requiresScreenshot(): array
    {
        return [
            self::PLATE,
            self::EDGE,
            self::FITTING,
        ];
    }

    /**
     * Cost driver types resolved via internal data (price lists, rates)
     * rather than URL scraping. These items are auto-closed at creation
     * and must not enter the scraping job pipeline.
     */
    public static function internalOnlyTypes(): array
    {
        return [
            self::OPERATION,
            self::LABOR_WORK,
            self::EXPENSE,
        ];
    }
}
