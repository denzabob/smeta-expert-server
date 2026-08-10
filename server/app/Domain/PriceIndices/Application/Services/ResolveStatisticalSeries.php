<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Illuminate\Database\QueryException;

class ResolveStatisticalSeries
{
    public function execute(
        StatisticalDataset $dataset,
        StatisticalIndicator $indicator,
        StatisticalClassifierItem $classifierItem,
        StatisticalTerritory $territory,
        string $frequency,
        string $comparisonBasis,
        string $unit
    ): StatisticalSeries {
        if ($indicator->dataset_id !== $dataset->id
            || $classifierItem->dataset_id !== $dataset->id
        ) {
            throw new PriceIndicesInvariantViolation(
                'Series dimensions must belong to the requested dataset.'
            );
        }

        $dimensions = [
            'dataset_id' => $dataset->id,
            'indicator_id' => $indicator->id,
            'classifier_item_id' => $classifierItem->id,
            'territory_id' => $territory->id,
            'frequency' => $frequency,
            'comparison_basis' => $comparisonBasis,
            'unit' => $unit,
        ];

        try {
            return StatisticalSeries::query()->firstOrCreate($dimensions);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            return StatisticalSeries::query()->where($dimensions)->sole();
        }
    }
}
