<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Database\Eloquent\Collection;

final class ListPublicIndexObservations
{
    public function execute(StatisticalPublicSeriesPage $page): Collection
    {
        return StatisticalObservation::query()
            ->select(['period_start', 'value'])
            ->where('import_id', $page->import_id)
            ->where('series_id', $page->series_id)
            ->orderBy('period_start')
            ->get();
    }
}
