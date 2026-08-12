<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPublicIndexPages
{
    private const PER_PAGE = 50;

    public function execute(): LengthAwarePaginator
    {
        return StatisticalPublicSeriesPage::query()
            ->where('is_indexable', true)
            ->whereNotNull('slug')
            ->with([
                'classifierItem:id,item_code,name',
                'series:id,public_id',
            ])
            ->orderBy('slug')
            ->paginate(self::PER_PAGE);
    }
}
