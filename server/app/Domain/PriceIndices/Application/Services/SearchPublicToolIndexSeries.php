<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SearchPublicToolIndexSeries
{
    public function __construct(
        private readonly StatisticalNameNormalizer $names,
        private readonly PublicIndexFamilyRegistry $families,
        private readonly PublicPriceIndexUrl $urls,
    ) {}

    /** @return list<array<string, mixed>> */
    public function execute(string $query, ?string $familyCode, int $limit): array
    {
        $normalized = mb_substr($this->names->normalize($query), 0, 120, 'UTF-8');
        if ($normalized === '') {
            return [];
        }

        $codeQuery = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
        $codePattern = $this->likePattern($codeQuery);
        $namePattern = $this->likePattern($normalized);
        $codePrefix = $codePattern.'%';
        $namePrefix = $namePattern.'%';
        $nameContains = '%'.$namePattern.'%';
        $terms = $this->searchTerms($normalized);

        $queryBuilder = DB::table('statistical_public_series_pages as pages')
            ->join('statistical_datasets as datasets', 'datasets.id', '=', 'pages.dataset_id')
            ->join('statistical_series as series', 'series.id', '=', 'pages.series_id')
            ->join('statistical_classifier_items as items', 'items.id', '=', 'pages.classifier_item_id')
            ->where('pages.is_indexable', true)
            ->whereNotNull('pages.slug')
            ->whereNotNull('pages.period_from')
            ->whereNotNull('pages.period_to')
            ->where('pages.observations_count', '>', 0)
            ->whereColumn('series.dataset_id', 'pages.dataset_id')
            ->whereColumn('items.dataset_id', 'pages.dataset_id')
            ->where('series.frequency', 'monthly')
            ->where('series.comparison_basis', 'previous_month')
            ->where('series.unit', 'percent');

        $families = $familyCode === null
            ? $this->families->all()
            : [$this->families->get($familyCode)];
        $queryBuilder->where(function ($familyQuery) use ($families): void {
            foreach ($families as $family) {
                $datasetSql = $this->families->datasetSql($family, 'datasets.code');
                $familyQuery->orWhereRaw($datasetSql['sql'], $datasetSql['bindings']);
            }
        });

        $queryBuilder->where(function ($search) use (
            $codeQuery,
            $codePrefix,
            $namePrefix,
            $nameContains,
            $terms,
        ): void {
            $search
                ->where('items.item_code', $codeQuery)
                ->orWhereRaw("items.item_code LIKE ? ESCAPE '!'", [$codePrefix])
                ->orWhereRaw("items.normalized_name LIKE ? ESCAPE '!'", [$namePrefix])
                ->orWhereRaw("items.normalized_name LIKE ? ESCAPE '!'", [$nameContains]);

            if ($terms !== []) {
                $search->orWhere(function ($termQuery) use ($terms): void {
                    foreach ($terms as $term) {
                        $termQuery->whereRaw(
                            "items.normalized_name LIKE ? ESCAPE '!'",
                            ['%'.$this->likePattern($term).'%'],
                        );
                    }
                });
            }
        });

        $rows = $queryBuilder
            ->select([
                'pages.slug',
                'datasets.code as dataset_code',
                'items.name as title',
                'items.item_code as code',
                'series.unit',
                'pages.period_from as min_period',
                'pages.period_to as max_period',
            ])
            ->orderByRaw(
                "CASE
                    WHEN items.item_code = ? THEN 0
                    WHEN items.item_code LIKE ? ESCAPE '!' THEN 1
                    WHEN items.normalized_name LIKE ? ESCAPE '!' THEN 2
                    ELSE 3
                END",
                [$codeQuery, $codePrefix, $namePrefix],
            )
            ->orderBy('items.item_code')
            ->limit(min(max($limit, 1), 20))
            ->get();

        return $rows->map(function (object $row): array {
            $family = $this->families->findForDataset((string) $row->dataset_code);

            return [
                'slug' => (string) $row->slug,
                'family' => $family?->code,
                'family_label' => $family?->publicLabel,
                'title' => (string) $row->title,
                'code' => $row->code === null ? null : (string) $row->code,
                'unit' => (string) $row->unit,
                'min_period' => $this->period($row->min_period),
                'max_period' => $this->period($row->max_period),
                'detail_url' => $family === null
                    ? null
                    : $this->urls->detail((string) $row->slug, $family->code),
            ];
        })->values()->all();
    }

    /** @return list<string> */
    private function searchTerms(string $query): array
    {
        if (preg_match('/\p{L}/u', $query) !== 1) {
            return [];
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach (array_slice($words, 0, 8) as $word) {
            if (mb_strlen($word, 'UTF-8') < 3) {
                continue;
            }

            $stem = preg_replace(
                '/(?:иями|ями|ами|ого|его|ому|ему|ая|яя|ой|ей|ий|ый|ое|ее|ые|ие|ую|юю|ых|их|ам|ям|ах|ях|ь|ы|и|а|я)$/u',
                '',
                $word,
            ) ?? $word;
            $terms[] = mb_strlen($stem, 'UTF-8') >= 3 ? $stem : $word;
        }

        return array_values(array_unique($terms));
    }

    private function likePattern(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function period(mixed $value): ?string
    {
        return is_string($value) ? CarbonImmutable::parse($value)->format('Y-m') : null;
    }
}
