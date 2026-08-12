<?php

namespace App\Domain\PriceIndices\Application\Support;

use InvalidArgumentException;

final class DecimalMath
{
    public const INTERNAL_SCALE = 20;
    public const COEFFICIENT_SCALE = 12;
    public const AMOUNT_SCALE = 2;

    public function multiply(string $left, string $right, int $scale = self::INTERNAL_SCALE): string
    {
        return bcmul($left, $right, $scale);
    }

    public function subtract(string $left, string $right, int $scale = self::INTERNAL_SCALE): string
    {
        return bcsub($left, $right, $scale);
    }

    public function divide(string $dividend, string $divisor, int $scale = self::INTERNAL_SCALE): string
    {
        if (bccomp($divisor, '0', $scale) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv($dividend, $divisor, $scale);
    }

    public function compare(string $left, string $right, int $scale = self::INTERNAL_SCALE): int
    {
        return bccomp($left, $right, $scale);
    }

    public function roundHalfUp(string $value, int $scale): string
    {
        if ($scale < 0 || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException('A valid decimal and non-negative scale are required.');
        }

        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $increment = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $increment, $scale);

        return $negative && bccomp($rounded, '0', $scale) !== 0 ? '-'.$rounded : $rounded;
    }
}
