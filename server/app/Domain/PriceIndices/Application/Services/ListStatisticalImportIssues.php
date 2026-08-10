<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListStatisticalImportIssues
{
    /** @param array<string, mixed> $filters */
    public function execute(StatisticalImport $import, array $filters): LengthAwarePaginator
    {
        $query = $import->issues()->getQuery();

        foreach (['severity', 'code', 'sheet_name'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? (int) config('price_indices.api.issues_per_page', 100));
    }
}
