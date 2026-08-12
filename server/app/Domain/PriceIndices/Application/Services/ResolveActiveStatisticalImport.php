<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;

final class ResolveActiveStatisticalImport
{
    public function forSearch(?string $datasetPublicId): ?StatisticalImport
    {
        if ($datasetPublicId !== null) {
            $dataset = StatisticalDataset::query()
                ->where('public_id', $datasetPublicId)
                ->where('is_enabled', true)
                ->first();

            return $dataset === null ? null : $this->forDatasetId($dataset->id);
        }

        $pointers = StatisticalDatasetActiveImport::query()
            ->whereHas('dataset', fn ($query) => $query->where('is_enabled', true))
            ->with(['import.dataset', 'import.sourceFile'])
            ->limit(2)
            ->get();

        if ($pointers->count() > 1) {
            throw new PriceIndicesApiException(
                'dataset_required',
                422,
                'dataset_public_id is required when multiple datasets are published.'
            );
        }

        return $pointers->first()?->import;
    }

    public function forDatasetId(int $datasetId): ?StatisticalImport
    {
        return StatisticalDatasetActiveImport::query()
            ->where('dataset_id', $datasetId)
            ->with(['import.dataset', 'import.sourceFile'])
            ->first()?->import;
    }
}
