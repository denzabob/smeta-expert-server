<?php

namespace App\Domain\PriceIndices\Application\Services;

use Illuminate\Pagination\LengthAwarePaginator;

final class ListActiveStatisticalSeries
{
    public function __construct(
        private readonly ResolveActiveStatisticalImport $activeImports,
        private readonly ListStatisticalImportSeries $series,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        $import = $this->activeImports->forSearch($filters['dataset_public_id'] ?? null);
        if ($import === null) {
            return new LengthAwarePaginator(
                [],
                0,
                $filters['per_page'] ?? 25,
                $filters['page'] ?? 1,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        }

        unset($filters['dataset_public_id']);

        return $this->series->execute($import, $filters);
    }
}
