<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPublicIndexPages
{
    private const PER_PAGE = 50;

    private const SEARCH_PER_PAGE = 20;

    public function __construct(
        private readonly StatisticalNameNormalizer $names,
        private readonly PublicIndexFamilyRegistry $families,
    ) {}

    public function execute(?string $searchQuery = null): LengthAwarePaginator
    {
        $query = StatisticalPublicSeriesPage::query()
            ->join(
                'statistical_classifier_items as search_items',
                'search_items.id',
                '=',
                'statistical_public_series_pages.classifier_item_id'
            )
            ->join('statistical_datasets as public_datasets', 'public_datasets.id', '=', 'statistical_public_series_pages.dataset_id')
            ->select([
                'statistical_public_series_pages.*',
                'public_datasets.code as public_dataset_code',
            ])
            ->where('is_indexable', true)
            ->whereNotNull('slug')
            ->with([
                'classifierItem:id,classifier_code,item_code,name,metadata_json',
                'series:id,public_id',
            ]);

        $query->where(function ($families): void {
            foreach ($this->families->all() as $family) {
                $datasetSql = $this->families->datasetSql($family, 'public_datasets.code');
                $families->orWhereRaw($datasetSql['sql'], $datasetSql['bindings']);
            }
        });

        $normalized = $searchQuery === null ? '' : $this->normalizedQuery($searchQuery);
        if ($normalized !== '') {
            $phrase = $this->likePattern($normalized);
            $prefix = $phrase.'%';
            $contains = '%'.$phrase.'%';
            $terms = $this->searchTerms($normalized);

            $query->where(function ($search) use ($normalized, $prefix, $contains, $terms): void {
                $search->where('search_items.item_code', $normalized)
                    ->orWhereRaw("search_items.item_code LIKE ? ESCAPE '!'", [$prefix])
                    ->orWhereRaw("search_items.normalized_name LIKE ? ESCAPE '!'", [$contains]);

                if ($terms !== []) {
                    $search->orWhere(function ($termQuery) use ($terms): void {
                        foreach ($terms as $term) {
                            $termQuery->whereRaw(
                                "search_items.normalized_name LIKE ? ESCAPE '!'",
                                ['%'.$this->likePattern($term).'%']
                            );
                        }
                    });
                }
            })->orderByRaw(
                "CASE
                    WHEN search_items.item_code = ? THEN 0
                    WHEN search_items.item_code LIKE ? ESCAPE '!' THEN 1
                    WHEN search_items.normalized_name = ? THEN 2
                    WHEN search_items.normalized_name LIKE ? ESCAPE '!' THEN 3
                    ELSE 4
                END",
                [$normalized, $prefix, $normalized, $contains]
            );
        }

        return $query
            ->orderBy('statistical_public_series_pages.slug')
            ->paginate($normalized === '' ? self::PER_PAGE : self::SEARCH_PER_PAGE);
    }

    public function normalizedQuery(string $query): string
    {
        return mb_substr($this->names->normalize($query), 0, 120, 'UTF-8');
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

    /** @return list<string> */
    private function searchTerms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach (array_slice($words, 0, 8) as $word) {
            if (mb_strlen($word, 'UTF-8') < 3) {
                continue;
            }

            $stem = preg_replace(
                '/(?:иями|ями|ами|ого|его|ому|ему|ая|яя|ой|ей|ий|ый|ое|ее|ые|ие|ую|юю|ых|их|ам|ям|ах|ях|ь|ы|и|а|я)$/u',
                '',
                $word
            ) ?? $word;
            $terms[] = mb_strlen($stem, 'UTF-8') >= 3 ? $stem : $word;
        }

        return array_values(array_unique($terms));
    }

    private function likePattern(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
