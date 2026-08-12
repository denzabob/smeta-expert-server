<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriodRange;
use InvalidArgumentException;
use Tests\TestCase;

class MonthlyPeriodTest extends TestCase
{
    public function test_strict_period_and_calendar_year_transition(): void
    {
        $november = MonthlyPeriod::parse('2024-11');
        $this->assertSame('2024-11-01', $november->date());
        $this->assertSame('2024-12', $november->next()->canonical());
        $this->assertSame('2025-01', $november->next()->next()->canonical());
    }

    public function test_exclusive_start_inclusive_end_range(): void
    {
        $periods = app(MonthlyPeriodRange::class)->exclusiveStartInclusiveEnd(
            MonthlyPeriod::parse('2024-11'),
            MonthlyPeriod::parse('2025-01')
        );
        $this->assertSame(['2024-12', '2025-01'], array_map(fn ($period) => $period->canonical(), $periods));
    }

    public function test_same_period_has_empty_range(): void
    {
        $period = MonthlyPeriod::parse('2024-01');
        $this->assertSame([], app(MonthlyPeriodRange::class)->exclusiveStartInclusiveEnd($period, $period));
    }

    public function test_non_strict_or_invalid_month_is_rejected(): void
    {
        foreach (['2024-1', '2024-13', '01.2024', '2024-01-01'] as $invalid) {
            try {
                MonthlyPeriod::parse($invalid);
                $this->fail("{$invalid} should be invalid.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
