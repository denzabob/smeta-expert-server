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

    public function latestDataYear(): ?int
    {
        $latestPeriod = StatisticalPublicSeriesPage::query()
            ->where('is_indexable', true)
            ->max('period_to');

        if (! is_string($latestPeriod) || preg_match('/^(\d{4})-\d{2}-\d{2}$/D', $latestPeriod, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
