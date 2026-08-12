<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ActiveStatisticalSeries;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;

final class GetActiveStatisticalSeries
{
    public function __construct(
        private readonly ResolveActiveStatisticalImport $activeImports,
        private readonly ListStatisticalImportSeries $series,
    ) {
    }

    public function execute(string $seriesPublicId, bool $noActiveAsConflict = false): ActiveStatisticalSeries
    {
        $candidate = StatisticalSeries::query()->where('public_id', $seriesPublicId)->first();
        if ($candidate === null) {
            throw $this->notAvailable();
        }

        $import = $this->activeImports->forDatasetId($candidate->dataset_id);
        if ($import === null) {
            if ($noActiveAsConflict) {
                throw new PriceIndicesApiException(
                    'no_active_publication',
                    409,
                    'No active publication is available for this dataset.'
                );
            }
            throw $this->notAvailable();
        }

        $paginator = $this->series->execute($import, [
            'series_public_id' => $seriesPublicId,
            'per_page' => 1,
        ]);
        $series = $paginator->getCollection()->first();
        if (! $series instanceof StatisticalSeries) {
            throw $this->notAvailable();
        }

        $series->setRelation('activeImportContext', $import);

        return new ActiveStatisticalSeries($import, $series);
    }

    private function notAvailable(): PriceIndicesApiException
    {
        return new PriceIndicesApiException(
            'series_not_available',
            404,
            'The statistical series is not available in the active publication.'
        );
    }
}
