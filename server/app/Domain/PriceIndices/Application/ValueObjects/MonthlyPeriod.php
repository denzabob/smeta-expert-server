<?php

namespace App\Domain\PriceIndices\Application\ValueObjects;

use InvalidArgumentException;

final readonly class MonthlyPeriod
{
    private function __construct(public int $year, public int $month)
    {
    }

    public static function parse(string $value): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/D', $value, $matches)) {
            throw new InvalidArgumentException('Monthly period must use YYYY-MM.');
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        if ($year < 1 || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('Monthly period is outside the calendar range.');
        }

        return new self($year, $month);
    }

    public function canonical(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function date(): string
    {
        return $this->canonical().'-01';
    }

    public function compare(self $other): int
    {
        return ($this->year * 12 + $this->month) <=> ($other->year * 12 + $other->month);
    }

    public function next(): self
    {
        return $this->month === 12
            ? new self($this->year + 1, 1)
            : new self($this->year, $this->month + 1);
    }
}
