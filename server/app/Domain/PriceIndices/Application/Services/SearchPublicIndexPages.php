<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\PublicIndexSearchResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Pagination\Paginator as PageResolver;
use Illuminate\Support\Facades\DB;

final class SearchPublicIndexPages
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly StatisticalNameNormalizer $names,
        private readonly PublicIndexFamilyRegistry $families,
    ) {}

    public function execute(string $searchQuery): LengthAwarePaginator
    {
        $normalized = mb_substr($this->names->normalize($searchQuery), 0, 120, 'UTF-8');
        if ($normalized === '') {
            return new Paginator([], 0, self::PER_PAGE, PageResolver::resolveCurrentPage());
        }

        $activeVersion = $this->activeOkpd2Version();
        $aliasFamily = $this->families->matchingSearchAlias($normalized);
        $statistical = $this->statisticalResultsQuery($normalized, $activeVersion, $aliasFamily);
        $combined = $activeVersion === null
            ? $statistical
            : $statistical->unionAll($this->classifierOnlyResultsQuery($normalized, $activeVersion));
        $page = PageResolver::resolveCurrentPage();
        $total = DB::query()->fromSub(clone $combined, 'public_index_search_count')->count();
        $rows = DB::query()
            ->fromSub(clone $combined, 'public_index_search_results')
            ->orderBy('relevance_rank')
            ->orderBy('code')
            ->orderBy('result_type')
            ->orderBy('stable_id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        return new Paginator(
            $rows->map(fn (object $row): PublicIndexSearchResult => $this->result($row)),
            $total,
            self::PER_PAGE,
            $page,
        );
    }

    private function activeOkpd2Version(): ?object
    {
        return DB::table('statistical_classifiers as classifiers')
            ->join('statistical_classifier_active_versions as active', 'active.classifier_id', '=', 'classifiers.id')
            ->join('statistical_classifier_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'active.classifier_version_id')
                    ->on('versions.classifier_id', '=', 'classifiers.id');
            })
            ->where('classifiers.code', 'okpd2')
            ->select([
                'versions.id',
                'versions.public_id',
                'versions.version_label',
            ])
            ->first();
    }

    private function statisticalResultsQuery(
        string $normalized,
        ?object $activeVersion,
        ?\App\Domain\PriceIndices\Application\Data\PublicIndexFamilyDescriptor $aliasFamily,
    ): Builder {
        $query = DB::table('statistical_public_series_pages as pages')
            ->join('statistical_classifier_items as items', 'items.id', '=', 'pages.classifier_item_id')
            ->join('statistical_datasets as datasets', 'datasets.id', '=', 'pages.dataset_id')
            ->where('pages.is_indexable', true)
            ->whereNotNull('pages.slug');
        $query->where(function ($families): void {
            foreach ($this->families->all() as $family) {
                $datasetSql = $this->families->datasetSql($family, 'datasets.code');
                $families->orWhereRaw($datasetSql['sql'], $datasetSql['bindings']);
            }
        });

        if ($activeVersion !== null) {
            $producer = $this->families->get(PublicIndexFamilyRegistry::PRODUCER_PRICES);
            $producerSql = $this->families->datasetSql($producer, 'datasets.code');
            $query->leftJoin('statistical_classifier_item_mappings as mappings', function ($join) use ($activeVersion, $producerSql): void {
                $join->on('mappings.statistical_classifier_item_id', '=', 'items.id')
                    ->whereRaw($producerSql['sql'], $producerSql['bindings'])
                    ->where('mappings.classifier_version_id', $activeVersion->id)
                    ->where('mappings.review_status', 'confirmed')
                    ->whereNotNull('mappings.classifier_node_id');
            })->leftJoin('statistical_classifier_nodes as nodes', function ($join) use ($activeVersion): void {
                $join->on('nodes.id', '=', 'mappings.classifier_node_id')
                    ->where('nodes.classifier_version_id', $activeVersion->id);
            });
            $code = 'COALESCE(nodes.code, items.item_code)';
            $name = 'COALESCE(nodes.normalized_name, items.normalized_name)';
        } else {
            $code = 'items.item_code';
            $name = 'items.normalized_name';
        }

        $this->applySearch($query, $code, $name, $normalized, $aliasFamily);
        [$rankSql, $rankBindings] = $this->rankExpression($code, $name, $normalized, $aliasFamily);
        $select = [
            DB::raw("'statistical_series' AS result_type"),
            'pages.id as stable_id',
            'datasets.code as dataset_code',
            DB::raw("{$code} AS code"),
            DB::raw($activeVersion === null ? 'items.name AS name' : 'COALESCE(nodes.name, items.name) AS name'),
            'items.classifier_code as local_classifier_code',
            'items.metadata_json as local_metadata_json',
            DB::raw($activeVersion === null ? 'NULL AS semantic_level' : 'nodes.semantic_level AS semantic_level'),
            DB::raw($activeVersion === null ? 'NULL AS classifier_version_public_id' : 'CASE WHEN nodes.id IS NULL THEN NULL ELSE '.DB::getPdo()->quote((string) $activeVersion->public_id).' END AS classifier_version_public_id'),
            DB::raw($activeVersion === null ? 'NULL AS classifier_version_label' : 'CASE WHEN nodes.id IS NULL THEN NULL ELSE '.DB::getPdo()->quote((string) $activeVersion->version_label).' END AS classifier_version_label'),
            DB::raw($activeVersion === null ? '0 AS has_rosstat_data' : 'CASE WHEN nodes.id IS NULL THEN 0 ELSE 1 END AS has_rosstat_data'),
            'pages.slug as statistical_slug',
            'pages.period_from',
            'pages.period_to',
            'pages.change_percent',
            'pages.coefficient',
            DB::raw($rankSql.' AS relevance_rank'),
        ];

        return $query->select($select)->addBinding($rankBindings, 'select');
    }

    private function classifierOnlyResultsQuery(string $normalized, object $activeVersion): Builder
    {
        $query = DB::table('statistical_classifier_nodes as nodes')
            ->where('nodes.classifier_version_id', $activeVersion->id)
            ->whereNotExists(function ($linkedPage) use ($activeVersion): void {
                $linkedPage->selectRaw('1')
                    ->from('statistical_classifier_item_mappings as linked_mappings')
                    ->join('statistical_public_series_pages as linked_pages', 'linked_pages.classifier_item_id', '=', 'linked_mappings.statistical_classifier_item_id')
                    ->whereColumn('linked_mappings.classifier_node_id', 'nodes.id')
                    ->where('linked_mappings.classifier_version_id', $activeVersion->id)
                    ->where('linked_mappings.review_status', 'confirmed')
                    ->where('linked_pages.is_indexable', true)
                    ->whereNotNull('linked_pages.slug');
            });
        $this->applySearch($query, 'nodes.code', 'nodes.normalized_name', $normalized);

        return $query->select([
            DB::raw("'classifier_node' AS result_type"),
            'nodes.id as stable_id',
            DB::raw('NULL AS dataset_code'),
            'nodes.code',
            'nodes.name',
            DB::raw('NULL AS local_classifier_code'),
            DB::raw('NULL AS local_metadata_json'),
            'nodes.semantic_level',
            DB::raw(DB::getPdo()->quote((string) $activeVersion->public_id).' AS classifier_version_public_id'),
            DB::raw(DB::getPdo()->quote((string) $activeVersion->version_label).' AS classifier_version_label'),
            DB::raw('0 AS has_rosstat_data'),
            DB::raw('NULL AS statistical_slug'),
            DB::raw('NULL AS period_from'),
            DB::raw('NULL AS period_to'),
            DB::raw('NULL AS change_percent'),
            DB::raw('NULL AS coefficient'),
            DB::raw($this->rankSql('nodes.code', 'nodes.normalized_name').' AS relevance_rank'),
        ])->addBinding($this->rankBindings($normalized), 'select');
    }

    private function applySearch(
        Builder $query,
        string $code,
        string $name,
        string $normalized,
        ?\App\Domain\PriceIndices\Application\Data\PublicIndexFamilyDescriptor $aliasFamily = null,
    ): void {
        $prefix = $this->likePattern($normalized).'%';
        $contains = '%'.$this->likePattern($normalized).'%';
        $terms = $this->searchTerms($normalized);

        $query->where(function ($search) use ($code, $name, $normalized, $prefix, $contains, $terms, $aliasFamily): void {
            $search->whereRaw("{$code} = ?", [$normalized])
                ->orWhereRaw("{$code} LIKE ? ESCAPE '!'", [$prefix])
                ->orWhereRaw("{$name} LIKE ? ESCAPE '!'", [$prefix])
                ->orWhereRaw("{$name} LIKE ? ESCAPE '!'", [$contains]);

            if ($terms !== []) {
                $search->orWhere(function ($termQuery) use ($name, $terms): void {
                    foreach ($terms as $term) {
                        $termQuery->whereRaw("{$name} LIKE ? ESCAPE '!'", ['%'.$this->likePattern($term).'%']);
                    }
                });
            }

            if ($aliasFamily !== null) {
                $datasetSql = $this->families->datasetSql($aliasFamily, 'datasets.code');
                $search->orWhereRaw($datasetSql['sql'], $datasetSql['bindings']);
            }
        });
    }

    /** @return array{string, list<string>} */
    private function rankExpression(
        string $code,
        string $name,
        string $normalized,
        ?\App\Domain\PriceIndices\Application\Data\PublicIndexFamilyDescriptor $aliasFamily,
    ): array {
        if ($aliasFamily === null) {
            return [$this->rankSql($code, $name), $this->rankBindings($normalized)];
        }

        $datasetSql = $this->families->datasetSql($aliasFamily, 'datasets.code');
        $sql = 'CASE '
            .'WHEN '.$datasetSql['sql'].' AND items.item_code = ? THEN 0 '
            .'WHEN '.$datasetSql['sql'].' THEN 1 '
            .'ELSE 10 + '.$this->rankSql($code, $name).' END';

        return [$sql, [
            ...$datasetSql['bindings'],
            (string) $aliasFamily->primaryItemCode,
            ...$datasetSql['bindings'],
            ...$this->rankBindings($normalized),
        ]];
    }

    private function rankSql(string $code, string $name): string
    {
        return "CASE
            WHEN {$code} = ? THEN 0
            WHEN {$code} LIKE ? ESCAPE '!' THEN 1
            WHEN {$name} LIKE ? ESCAPE '!' THEN 2
            WHEN {$name} LIKE ? ESCAPE '!' THEN 3
            ELSE 4
        END";
    }

    /** @return list<string> */
    private function rankBindings(string $normalized): array
    {
        $escaped = $this->likePattern($normalized);

        return [$normalized, $escaped.'%', $escaped.'%', '%'.$escaped.'%'];
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

    private function result(object $row): PublicIndexSearchResult
    {
        $metadata = json_decode((string) ($row->local_metadata_json ?? ''), true);
        $providerCodeKind = is_array($metadata) && is_string($metadata['provider_code_kind'] ?? null)
            ? $metadata['provider_code_kind']
            : null;

        $family = $row->dataset_code === null
            ? null
            : $this->families->findForDataset((string) $row->dataset_code);

        return new PublicIndexSearchResult(
            (string) $row->result_type,
            $family?->code,
            $family?->searchLabel,
            (string) $row->code,
            (string) $row->name,
            $row->classifier_version_public_id === null ? null : 'ОКПД2',
            $row->local_classifier_code === null ? null : (string) $row->local_classifier_code,
            $providerCodeKind,
            $row->semantic_level === null ? null : (string) $row->semantic_level,
            $row->classifier_version_public_id === null ? null : (string) $row->classifier_version_public_id,
            $row->classifier_version_label === null ? null : (string) $row->classifier_version_label,
            (bool) $row->has_rosstat_data,
            $row->statistical_slug === null ? null : (string) $row->statistical_slug,
            $row->period_from === null ? null : CarbonImmutable::parse($row->period_from),
            $row->period_to === null ? null : CarbonImmutable::parse($row->period_to),
            $row->change_percent === null ? null : (string) $row->change_percent,
            $row->coefficient === null ? null : (string) $row->coefficient,
        );
    }
}
