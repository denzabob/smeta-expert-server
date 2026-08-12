<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\PublicStatisticalSeriesSnapshot;
use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Application\Support\PublicIndexSlug;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriod;
use App\Domain\PriceIndices\Application\ValueObjects\MonthlyPeriodRange;
use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;

final class BuildPublicStatisticalSeriesSnapshot
{
    public function __construct(
        private readonly PublicIndexSlug $slugs,
        private readonly DecimalMath $decimal,
        private readonly MonthlyPeriodRange $periodRange,
        private readonly CalculateStatisticalIndexChain $calculator,
    ) {
    }

    public function execute(StatisticalImport $import, StatisticalSeries $series): PublicStatisticalSeriesSnapshot
    {
        $series->loadMissing(['classifierItem', 'indicator', 'territory']);
        $import->loadMissing(['dataset', 'sourceFile']);
        $item = $series->classifierItem;
        $slug = $item === null ? null : $this->slugs->fromItemCode((string) $item->item_code);

        $observations = StatisticalObservation::query()
            ->where('import_id', $import->id)
            ->where('series_id', $series->id)
            ->orderBy('period_start')
            ->get();

        $periodFrom = $observations->first()?->period_start?->format('Y-m-d');
        $periodTo = $observations->last()?->period_start?->format('Y-m-d');
        $base = [
            'import' => $import,
            'series' => $series,
            'slug' => $slug,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'observationsCount' => $observations->count(),
        ];

        if ($series->dataset_id !== $import->dataset_id
            || $import->source_file_id === null
            || $item === null
            || trim((string) $item?->classifier_code) === ''
            || trim((string) $item?->item_code) === ''
            || trim((string) $item?->name) === ''
            || $slug === null
        ) {
            return $this->nonIndexable(PublicSeriesIndexabilityStatus::InvalidMetadata, $base);
        }

        if ($series->frequency !== 'monthly'
            || $series->comparison_basis !== 'previous_month'
            || $series->unit !== 'percent'
        ) {
            return $this->nonIndexable(PublicSeriesIndexabilityStatus::UnsupportedSeries, $base);
        }

        if ($periodFrom === null || $periodTo === null) {
            return $this->nonIndexable(PublicSeriesIndexabilityStatus::InsufficientHistory, $base);
        }

        $start = MonthlyPeriod::parse(substr($periodFrom, 0, 7));
        $end = MonthlyPeriod::parse(substr($periodTo, 0, 7));
        $expectedFactors = $this->periodRange->exclusiveStartInclusiveEnd($start, $end);
        $expectedPeriods = [$start->canonical(), ...array_map(
            fn (MonthlyPeriod $period): string => $period->canonical(),
            $expectedFactors
        )];
        $byPeriod = $observations->groupBy(
            fn (StatisticalObservation $observation): string => $observation->period_start->format('Y-m')
        );

        foreach ($expectedPeriods as $period) {
            $matches = $byPeriod->get($period, collect());
            if ($matches->count() !== 1) {
                return $this->nonIndexable(PublicSeriesIndexabilityStatus::IncompleteChain, $base);
            }
            $observation = $matches->first();
            if ($observation->value === null || $observation->missing_reason !== null) {
                return $this->nonIndexable(PublicSeriesIndexabilityStatus::IncompleteChain, $base);
            }
        }

        if ($observations->count() < 12) {
            return $this->nonIndexable(PublicSeriesIndexabilityStatus::InsufficientHistory, $base);
        }

        $minimum = null;
        $maximum = null;
        foreach ($observations as $observation) {
            $value = $observation->value;
            if (! is_string($value)
                || preg_match('/^\d+(?:\.\d+)?$/D', $value) !== 1
                || $this->decimal->compare($value, '0') <= 0
            ) {
                return $this->nonIndexable(PublicSeriesIndexabilityStatus::CalculationError, $base);
            }
            if ($minimum === null || $this->decimal->compare($value, $minimum->value) < 0) {
                $minimum = $observation;
            }
            if ($maximum === null || $this->decimal->compare($value, $maximum->value) > 0) {
                $maximum = $observation;
            }
        }

        $series->setAttribute('period_from', $periodFrom);
        $series->setAttribute('period_to', $periodTo);

        try {
            $calculation = $this->calculator->execute(
                $import,
                $series,
                $start->canonical(),
                $end->canonical(),
            );
        } catch (PriceIndicesApiException) {
            return $this->nonIndexable(PublicSeriesIndexabilityStatus::CalculationError, $base);
        }

        $coefficientRaw = $calculation['coefficient_raw'];
        $changePercentRaw = $this->decimal->multiply(
            $this->decimal->subtract($coefficientRaw, '1'),
            '100'
        );

        return new PublicStatisticalSeriesSnapshot(
            $import->dataset_id,
            $import->id,
            $series->id,
            $series->classifier_item_id,
            $import->source_file_id,
            $slug,
            PublicSeriesIndexabilityStatus::Indexable,
            $periodFrom,
            $periodTo,
            $observations->count(),
            count($expectedFactors),
            $coefficientRaw,
            $calculation['coefficient'],
            $changePercentRaw,
            $this->decimal->roundHalfUp($changePercentRaw, 2),
            $minimum?->value,
            $minimum?->period_start?->format('Y-m-d'),
            $maximum?->value,
            $maximum?->period_start?->format('Y-m-d'),
            now(),
            $import->published_at,
        );
    }

    /** @param array<string, mixed> $base */
    private function nonIndexable(
        PublicSeriesIndexabilityStatus $status,
        array $base,
    ): PublicStatisticalSeriesSnapshot {
        /** @var StatisticalImport $import */
        $import = $base['import'];
        /** @var StatisticalSeries $series */
        $series = $base['series'];

        return new PublicStatisticalSeriesSnapshot(
            $import->dataset_id,
            $import->id,
            $series->id,
            $series->classifier_item_id,
            $import->source_file_id,
            $base['slug'],
            $status,
            $base['periodFrom'],
            $base['periodTo'],
            $base['observationsCount'],
            0,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            now(),
            $import->published_at,
        );
    }
}
