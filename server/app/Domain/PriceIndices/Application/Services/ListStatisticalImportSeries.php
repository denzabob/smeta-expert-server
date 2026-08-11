<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ListStatisticalImportSeries
{
    public function __construct(private readonly StatisticalNameNormalizer $nameNormalizer)
    {
    }

    /** @param array<string, mixed> $filters */
    public function execute(StatisticalImport $import, array $filters): LengthAwarePaginator
    {
        $summary = DB::table('statistical_observations')
            ->select('series_id')
            ->selectRaw('MIN(period_start) as period_from')
            ->selectRaw('MAX(period_start) as period_to')
            ->selectRaw('COUNT(*) as observations_count')
            ->where('import_id', $import->id)
            ->groupBy('series_id');

        $query = StatisticalSeries::query()
            ->select([
                'statistical_series.*',
                'import_summary.period_from',
                'import_summary.period_to',
                'import_summary.observations_count',
            ])
            ->joinSub($summary, 'import_summary', function ($join): void {
                $join->on('import_summary.series_id', '=', 'statistical_series.id');
            })
            ->join(
                'statistical_classifier_items as query_items',
                'query_items.id',
                '=',
                'statistical_series.classifier_item_id'
            )
            ->where('statistical_series.dataset_id', $import->dataset_id)
            ->with(['classifierItem', 'indicator', 'territory']);

        if (isset($filters['item_code'])) {
            $query->where('query_items.item_code', $filters['item_code']);
        }
        if (isset($filters['item_code_prefix'])) {
            $query->where(
                'query_items.item_code',
                'like',
                $this->escapeLike($filters['item_code_prefix']).'%'
            );
        }
        if (isset($filters['item_name'])) {
            $normalizedName = $this->escapeLike($this->nameNormalizer->normalize($filters['item_name']));
            $query->where('query_items.normalized_name', 'like', "%{$normalizedName}%");
        }

        $sortColumn = ($filters['sort'] ?? 'item_code') === 'item_name'
            ? 'query_items.normalized_name'
            : 'query_items.item_code';

        return $query
            ->orderBy($sortColumn, $filters['direction'] ?? 'asc')
            ->orderBy('statistical_series.public_id')
            ->paginate($filters['per_page'] ?? 25);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
