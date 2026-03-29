<?php

namespace App\Evidence;

/**
 * Cost component types for the generic evidence domain.
 * Parallel to CostDriverType but scoped to the new EstimateEvidence* flow.
 */
final class CostComponent
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
     * Cost components resolved via internal data (price lists, rates)
     * rather than URL scraping. Auto-resolved at run creation.
     */
    public static function internalOnlyTypes(): array
    {
        return [
            self::OPERATION,
            self::LABOR_WORK,
            self::EXPENSE,
        ];
    }

    /**
     * Cost components that reference external URLs for price evidence.
     */
    public static function externalTypes(): array
    {
        return [
            self::PLATE,
            self::EDGE,
            self::FACADE,
            self::FITTING,
        ];
    }
}
