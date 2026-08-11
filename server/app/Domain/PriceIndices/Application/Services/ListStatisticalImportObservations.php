<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListStatisticalImportObservations
{
    public function __construct(private readonly StatisticalNameNormalizer $nameNormalizer)
    {
    }

    /** @param array<string, mixed> $filters */
    public function execute(StatisticalImport $import, array $filters): LengthAwarePaginator
    {
        $query = StatisticalObservation::query()
            ->select('statistical_observations.*')
            ->join('statistical_series as query_series', 'query_series.id', '=', 'statistical_observations.series_id')
            ->join('statistical_classifier_items as query_items', 'query_items.id', '=', 'query_series.classifier_item_id')
            ->where('statistical_observations.import_id', $import->id)
            ->with(['series.indicator', 'series.classifierItem', 'series.territory', 'sourceFile']);

        if (isset($filters['item_code'])) {
            $itemCode = $filters['item_code'];
            if (str_ends_with($itemCode, '.')) {
                $query->where('query_items.item_code', 'like', $this->escapeLike($itemCode).'%');
            } else {
                $query->where('query_items.item_code', $itemCode);
            }
        }
        if (isset($filters['item_name'])) {
            $name = $this->escapeLike($this->nameNormalizer->normalize($filters['item_name']));
            $query->where('query_items.normalized_name', 'like', "%{$name}%");
        }
        if (isset($filters['series_public_id'])) {
            $query->where('query_series.public_id', $filters['series_public_id']);
        }
        if (isset($filters['period_from'])) {
            $query->where('statistical_observations.period_start', '>=', $filters['period_from']);
        }
        if (isset($filters['period_to'])) {
            $query->where('statistical_observations.period_start', '<=', $filters['period_to']);
        }
        if (array_key_exists('missing', $filters)) {
            $filters['missing']
                ? $query->whereNotNull('statistical_observations.missing_reason')
                : $query->whereNull('statistical_observations.missing_reason');
        }
        if (isset($filters['sheet_name'])) {
            $query->where('statistical_observations.sheet_name', $filters['sheet_name']);
        }

        $sort = $filters['sort'] ?? 'period_start';
        $sortColumn = $sort === 'item_code' ? 'query_items.item_code' : 'statistical_observations.'.$sort;

        return $query
            ->orderBy($sortColumn, $filters['direction'] ?? 'asc')
            ->orderBy('statistical_observations.id')
            ->paginate($filters['per_page'] ?? (int) config('price_indices.api.observations_per_page', 100));
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
