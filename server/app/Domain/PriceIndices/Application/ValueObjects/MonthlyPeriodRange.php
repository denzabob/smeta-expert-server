<?php

namespace App\Domain\PriceIndices\Application\ValueObjects;

final class MonthlyPeriodRange
{
    /** @return list<MonthlyPeriod> */
    public function exclusiveStartInclusiveEnd(MonthlyPeriod $start, MonthlyPeriod $end): array
    {
        $periods = [];
        for ($period = $start->next(); $period->compare($end) <= 0; $period = $period->next()) {
            $periods[] = $period;
        }

        return $periods;
    }
}
