<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesApiException;
use Illuminate\Support\Facades\DB;

final class SearchPublicOkpd2
{
    private const MAX_LINEAGE_DEPTH = 32;

    /** @var list<string> */
    private const PUBLIC_MAPPING_TYPES = ['exact', 'parent_aggregate'];

    public function __construct(
        private readonly StatisticalNameNormalizer $names,
        private readonly PublicIndexFamilyRegistry $families,
        private readonly PublicPriceIndexUrl $urls,
    ) {}

    /** @return list<array<string, mixed>> */
    public function execute(string $query, int $limit): array
    {
        $normalized = mb_substr($this->names->normalize($query), 0, 120, 'UTF-8');
        $codeQuery = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
        $activeVersion = $this->activeVersion();
        if ($activeVersion === null) {
            throw new PriceIndicesApiException(
                'classifier_unavailable',
                503,
                'The active OKPD2 classifier is not available.',
            );
        }

        $codePattern = $this->likePattern($codeQuery);
        $namePattern = $this->likePattern($normalized);
        $codePrefix = $codePattern.'%';
        $namePrefix = $namePattern.'%';
        $nameContains = '%'.$namePattern.'%';
        $terms = $this->searchTerms($normalized);

        $nodesQuery = DB::table('statistical_classifier_nodes as nodes')
            ->where('nodes.classifier_version_id', $activeVersion->id)
            ->where(function ($search) use ($codeQuery, $codePrefix, $namePrefix, $nameContains, $terms): void {
                $search
                    ->where('nodes.code', $codeQuery)
                    ->orWhereRaw("nodes.code LIKE ? ESCAPE '!'", [$codePrefix])
                    ->orWhereRaw("nodes.normalized_name LIKE ? ESCAPE '!'", [$namePrefix])
                    ->orWhereRaw("nodes.normalized_name LIKE ? ESCAPE '!'", [$nameContains]);

                if ($terms !== []) {
                    $search->orWhere(function ($termQuery) use ($terms): void {
                        foreach ($terms as $term) {
                            $termQuery->whereRaw(
                                "nodes.normalized_name LIKE ? ESCAPE '!'",
                                ['%'.$this->likePattern($term).'%'],
                            );
                        }
                    });
                }
            });

        $rows = $nodesQuery
            ->select([
                'nodes.id',
                'nodes.code',
                'nodes.name',
                'nodes.formal_depth',
                'nodes.parent_node_id',
            ])
            ->orderByRaw(
                "CASE
                    WHEN nodes.code = ? THEN 0
                    WHEN nodes.code LIKE ? ESCAPE '!' THEN 1
                    WHEN nodes.normalized_name LIKE ? ESCAPE '!' THEN 2
                    ELSE 3
                END",
                [$codeQuery, $codePrefix, $namePrefix],
            )
            ->orderBy('nodes.code')
            ->limit(min(max($limit, 1), 20))
            ->get();

        $nodes = $this->loadLineage($activeVersion->id, $rows->all());
        $linkedIndexes = $this->linkedIndexes(
            $activeVersion->id,
            $rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );

        return $rows->map(function (object $row) use ($nodes, $linkedIndexes): array {
            $nodeId = (int) $row->id;
            $linked = $linkedIndexes[$nodeId] ?? null;

            return [
                'code' => (string) $row->code,
                'title' => (string) $row->name,
                'level' => $row->formal_depth === null ? null : (int) $row->formal_depth,
                'path' => $this->path($nodeId, $nodes),
                'price_index' => $linked ?? [
                    'available' => false,
                    'title' => null,
                    'url' => null,
                ],
            ];
        })->values()->all();
    }

    private function activeVersion(): ?object
    {
        return DB::table('statistical_classifiers as classifiers')
            ->join('statistical_classifier_active_versions as active', 'active.classifier_id', '=', 'classifiers.id')
            ->join('statistical_classifier_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'active.classifier_version_id')
                    ->on('versions.classifier_id', '=', 'classifiers.id');
            })
            ->where('classifiers.code', 'okpd2')
            ->select(['versions.id'])
            ->first();
    }

    /** @param list<object> $rows @return array<int, object> */
    private function loadLineage(int $versionId, array $rows): array
    {
        $nodes = [];
        $pending = [];
        foreach ($rows as $row) {
            $nodes[(int) $row->id] = $row;
            if ($row->parent_node_id !== null) {
                $pending[(int) $row->parent_node_id] = true;
            }
        }

        for ($depth = 0; $depth < self::MAX_LINEAGE_DEPTH && $pending !== []; $depth++) {
            $ids = array_keys($pending);
            $pending = [];
            $parents = DB::table('statistical_classifier_nodes')
                ->where('classifier_version_id', $versionId)
                ->whereIn('id', $ids)
                ->get(['id', 'code', 'name', 'formal_depth', 'parent_node_id']);

            foreach ($parents as $parent) {
                $parentId = (int) $parent->id;
                if (isset($nodes[$parentId])) {
                    continue;
                }
                $nodes[$parentId] = $parent;
                if ($parent->parent_node_id !== null) {
                    $pending[(int) $parent->parent_node_id] = true;
                }
            }
        }

        return $nodes;
    }

    /** @param list<int> $nodeIds @return array<int, array<string, mixed>> */
    private function linkedIndexes(int $versionId, array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $producerFamily = $this->families->get(PublicIndexFamilyRegistry::PRODUCER_PRICES);
        $datasetSql = $this->families->datasetSql($producerFamily, 'datasets.code');
        $rows = DB::table('statistical_classifier_item_mappings as mappings')
            ->join('statistical_classifier_items as items', 'items.id', '=', 'mappings.statistical_classifier_item_id')
            ->join('statistical_public_series_pages as pages', 'pages.classifier_item_id', '=', 'items.id')
            ->join('statistical_datasets as datasets', 'datasets.id', '=', 'pages.dataset_id')
            ->join('statistical_series as series', 'series.id', '=', 'pages.series_id')
            ->whereIn('mappings.classifier_node_id', $nodeIds)
            ->where('mappings.classifier_version_id', $versionId)
            ->where('mappings.review_status', 'confirmed')
            ->whereIn('mappings.mapping_type', self::PUBLIC_MAPPING_TYPES)
            ->where('pages.is_indexable', true)
            ->whereNotNull('pages.slug')
            ->where('series.frequency', 'monthly')
            ->where('series.comparison_basis', 'previous_month')
            ->where('series.unit', 'percent')
            ->whereRaw($datasetSql['sql'], $datasetSql['bindings'])
            ->select([
                'mappings.classifier_node_id',
                'pages.slug',
                'datasets.code as dataset_code',
                'items.name as title',
            ])
            ->orderByRaw("CASE WHEN mappings.mapping_type = 'exact' THEN 0 ELSE 1 END")
            ->orderBy('pages.slug')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $nodeId = (int) $row->classifier_node_id;
            if (isset($result[$nodeId])) {
                continue;
            }

            $family = $this->families->findForDataset((string) $row->dataset_code);
            if ($family === null) {
                continue;
            }

            $result[$nodeId] = [
                'available' => true,
                'title' => (string) $row->title,
                'url' => $this->urls->detail((string) $row->slug, $family->code),
            ];
        }

        return $result;
    }

    /** @param array<int, object> $nodes @return list<array{code:string,title:string}> */
    private function path(int $nodeId, array $nodes): array
    {
        $path = [];
        $currentId = $nodeId;
        for ($depth = 0; $depth < self::MAX_LINEAGE_DEPTH && isset($nodes[$currentId]); $depth++) {
            $node = $nodes[$currentId];
            array_unshift($path, [
                'code' => (string) $node->code,
                'title' => (string) $node->name,
            ]);
            if ($node->parent_node_id === null) {
                break;
            }
            $currentId = (int) $node->parent_node_id;
        }

        return $path;
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
}
