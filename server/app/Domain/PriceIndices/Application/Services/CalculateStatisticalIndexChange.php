<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\StatisticalCalculationInput;
use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriodRange;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Support\Facades\Log;

final class CalculateStatisticalIndexChange
{
    public function __construct(
        private readonly GetActiveStatisticalSeries $activeSeries,
        private readonly DecimalMath $decimal,
        private readonly MonthlyPeriodRange $periodRange,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(StatisticalCalculationInput $input): array
    {
        $context = $this->activeSeries->execute($input->seriesPublicId, true);
        $series = $context->series;
        $import = $context->import;

        if ($series->frequency !== 'monthly'
            || $series->comparison_basis !== 'previous_month'
            || $series->unit !== 'percent'
        ) {
            throw new PriceIndicesApiException(
                'unsupported_series_calculation',
                422,
                'Only monthly previous-month percent series are supported.'
            );
        }

        $start = MonthlyPeriod::parse($input->startPeriod);
        $end = MonthlyPeriod::parse($input->endPeriod);
        if ($start->compare($end) > 0) {
            throw new PriceIndicesApiException('invalid_period_range', 422, 'start_period must not be after end_period.');
        }

        $availableFrom = MonthlyPeriod::parse(substr((string) $series->period_from, 0, 7));
        $availableTo = MonthlyPeriod::parse(substr((string) $series->period_to, 0, 7));
        if ($start->compare($availableFrom) < 0) {
            throw new PriceIndicesApiException('period_before_available_range', 422, 'start_period is before the available range.');
        }
        if ($end->compare($availableTo) > 0) {
            throw new PriceIndicesApiException('period_after_available_range', 422, 'end_period is after the available range.');
        }

        $expected = $this->periodRange->exclusiveStartInclusiveEnd($start, $end);
        $observations = $expected === []
            ? collect()
            : StatisticalObservation::query()
                ->where('import_id', $import->id)
                ->where('series_id', $series->id)
                ->where('period_start', '>', $start->date())
                ->where('period_start', '<=', $end->date())
                ->orderBy('period_start')
                ->get();

        $byPeriod = $observations->groupBy(fn (StatisticalObservation $observation): string => $observation->period_start->format('Y-m'));
        $expectedNames = array_map(fn (MonthlyPeriod $period): string => $period->canonical(), $expected);
        $missingPeriods = [];
        $missingValuePeriods = [];
        $duplicatePeriods = [];

        foreach ($expectedNames as $period) {
            $matches = $byPeriod->get($period, collect());
            if ($matches->isEmpty()) {
                $missingPeriods[] = $period;
                continue;
            }
            if ($matches->count() > 1) {
                $duplicatePeriods[] = $period;
            }
            $observation = $matches->first();
            if ($observation->value === null || $observation->missing_reason !== null) {
                $missingValuePeriods[] = $period;
            }
        }

        if ($missingPeriods !== [] || $missingValuePeriods !== [] || $duplicatePeriods !== []) {
            throw new PriceIndicesApiException(
                'incomplete_observation_chain',
                422,
                'The observation chain is incomplete.',
                details: [
                    'missing_periods' => $missingPeriods,
                    'missing_value_periods' => $missingValuePeriods,
                    'duplicate_periods' => $duplicatePeriods,
                ]
            );
        }

        $running = '1.00000000000000000000';
        $chain = [];
        foreach ($expectedNames as $period) {
            /** @var StatisticalObservation $observation */
            $observation = $byPeriod->get($period)->first();
            $index = $observation->value;
            if (! is_string($index)
                || ! preg_match('/^\d+(?:\.\d+)?$/D', $index)
                || $this->decimal->compare($index, '0') <= 0
            ) {
                Log::error('Price Indices calculation integrity failure.', [
                    'series_public_id' => $series->public_id,
                    'import_public_id' => $import->public_id,
                    'period' => $period,
                ]);
                throw new PriceIndicesApiException(
                    'calculation_integrity_error',
                    500,
                    'The published observation chain cannot be calculated safely.'
                );
            }

            $factor = $this->decimal->divide($index, '100');
            $running = $this->decimal->multiply($running, $factor);
            $chain[] = [
                'period' => $period,
                'index' => $index,
                'factor' => $factor,
                'running_coefficient' => $this->decimal->roundHalfUp($running, DecimalMath::COEFFICIENT_SCALE),
                'source' => [
                    'sheet' => $observation->sheet_name,
                    'row' => $observation->source_row,
                    'column' => $observation->source_column,
                    'cell' => $observation->source_cell_address,
                    'raw_value' => $observation->source_value_raw,
                    'footnote_marker' => $observation->footnote_marker,
                ],
            ];
        }

        $amount = null;
        if ($input->baseAmount !== null) {
            $adjustedRaw = $this->decimal->multiply($input->baseAmount, $running);
            $amount = [
                'base' => $input->baseAmount,
                'adjusted_raw' => $adjustedRaw,
                'adjusted' => $this->decimal->roundHalfUp($adjustedRaw, DecimalMath::AMOUNT_SCALE),
            ];
        }

        return [
            'series' => $this->seriesPayload($series),
            'period' => [
                'start' => $start->canonical(),
                'end' => $end->canonical(),
                'interval_semantics' => '(start,end]',
                'factors_count' => count($chain),
            ],
            'coefficient_raw' => $running,
            'coefficient' => $this->decimal->roundHalfUp($running, DecimalMath::COEFFICIENT_SCALE),
            'amount' => $amount,
            'chain' => $chain,
            'provenance' => [
                'dataset' => [
                    'public_id' => $import->dataset->public_id,
                    'code' => $import->dataset->code,
                    'name' => $import->dataset->name,
                ],
                'import' => [
                    'public_id' => $import->public_id,
                    'importer_code' => $import->importer_code,
                    'importer_version' => $import->importer_version,
                    'published_at' => $import->published_at?->toISOString(),
                ],
                'source_file' => [
                    'public_id' => $import->sourceFile->public_id,
                    'original_filename' => $import->sourceFile->original_filename,
                    'sha256' => $import->sourceFile->sha256,
                ],
                'series' => ['public_id' => $series->public_id],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function seriesPayload(StatisticalSeries $series): array
    {
        return [
            'public_id' => $series->public_id,
            'classifier_item' => [
                'public_id' => $series->classifierItem->public_id,
                'item_code' => $series->classifierItem->item_code,
                'item_name' => $series->classifierItem->name,
            ],
            'indicator' => ['code' => $series->indicator->code, 'name' => $series->indicator->name],
            'territory' => ['code' => $series->territory->code, 'name' => $series->territory->name],
            'frequency' => $series->frequency,
            'comparison_basis' => $series->comparison_basis,
            'unit' => $series->unit,
        ];
    }
}
