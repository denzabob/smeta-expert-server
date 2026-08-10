<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListStatisticalImports
{
    /** @param array<string, mixed> $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = StatisticalImport::query()->with([
            'dataset', 'sourceFile', 'activePointer', 'supersedes',
        ]);

        if (isset($filters['dataset_public_id'])) {
            $query->whereHas('dataset', fn ($builder) => $builder->where('public_id', $filters['dataset_public_id']));
        }
        if (isset($filters['source_file_public_id'])) {
            $query->whereHas('sourceFile', fn ($builder) => $builder->where('public_id', $filters['source_file_public_id']));
        }
        foreach (['status', 'importer_code', 'importer_version'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from'].' 00:00:00');
        }
        if (isset($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to'].' 23:59:59');
        }

        return $query
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? (int) config('price_indices.api.imports_per_page', 25));
    }
}
