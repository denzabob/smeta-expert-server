<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Database\Eloquent\Collection;

final class ListPublicIndexSitemapEntries
{
    public function execute(): Collection
    {
        return StatisticalPublicSeriesPage::query()
            ->select(['slug', 'generated_at'])
            ->where('is_indexable', true)
            ->whereNotNull('slug')
            ->orderBy('slug')
            ->get();
    }
}
