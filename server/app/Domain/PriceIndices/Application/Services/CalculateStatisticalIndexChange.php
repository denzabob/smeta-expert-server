<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\StatisticalCalculationInput;

final class CalculateStatisticalIndexChange
{
    public function __construct(
        private readonly GetActiveStatisticalSeries $activeSeries,
        private readonly CalculateStatisticalIndexChain $calculator,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(StatisticalCalculationInput $input): array
    {
        $context = $this->activeSeries->execute($input->seriesPublicId, true);

        return $this->calculator->execute(
            $context->import,
            $context->series,
            $input->startPeriod,
            $input->endPeriod,
            $input->baseAmount,
        );
    }
}
