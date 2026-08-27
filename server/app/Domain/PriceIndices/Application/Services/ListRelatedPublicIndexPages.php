<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Database\Eloquent\Collection;

final class ListRelatedPublicIndexPages
{
    /** @return Collection<int, StatisticalPublicSeriesPage> */
    public function execute(StatisticalPublicSeriesPage $page, int $limit = 8): Collection
    {
        $itemCode = (string) $page->classifierItem?->item_code;
        $prefixes = $this->prefixes($itemCode);
        $caseParts = [];
        $bindings = [];

        foreach ($prefixes as $rank => $prefix) {
            $caseParts[] = 'WHEN related_items.item_code LIKE ? THEN '.(int) $rank;
            $bindings[] = $this->escapeLike($prefix).'%';
        }

        $query = StatisticalPublicSeriesPage::query()
            ->join('statistical_classifier_items as related_items', 'related_items.id', '=', 'statistical_public_series_pages.classifier_item_id')
            ->select('statistical_public_series_pages.*')
            ->where('statistical_public_series_pages.dataset_id', $page->dataset_id)
            ->where('statistical_public_series_pages.is_indexable', true)
            ->whereNotNull('statistical_public_series_pages.slug')
            ->where('statistical_public_series_pages.id', '!=', $page->getKey())
            ->with('classifierItem:id,item_code,name');
        if ($caseParts !== []) {
            $query->orderByRaw(
                'CASE '.implode(' ', $caseParts).' ELSE '.count($caseParts).' END',
                $bindings,
            );
        }

        return $query
            ->orderBy('related_items.item_code')
            ->limit(max(1, min($limit, 10)))
            ->get();
    }

    /** @return list<string> */
    private function prefixes(string $itemCode): array
    {
        $segments = array_values(array_filter(explode('.', trim($itemCode)), fn (string $part): bool => $part !== ''));
        $prefixes = [];

        for ($length = count($segments) - 1; $length >= 1; $length--) {
            $prefixes[] = implode('.', array_slice($segments, 0, $length)).'.';
        }

        return array_values(array_unique($prefixes));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
