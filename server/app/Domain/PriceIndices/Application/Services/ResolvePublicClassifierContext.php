<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\PublicClassifierContext;
use App\Domain\PriceIndices\Application\Data\PublicClassifierPosition;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

final class ResolvePublicClassifierContext
{
    private const CHILD_LIMIT = 15;

    private const MAX_LINEAGE_DEPTH = 32;

    /** @var list<string> */
    private const PUBLIC_MAPPING_TYPES = ['exact', 'parent_aggregate'];

    public function __construct(private readonly DatabaseManager $database) {}

    public function execute(StatisticalPublicSeriesPage $page): ?PublicClassifierContext
    {
        try {
            $activeVersion = $this->activeVersion();
            if ($activeVersion === null) {
                return null;
            }

            return $this->database->transaction(
                fn (): ?PublicClassifierContext => $this->resolve($page, $activeVersion),
                1,
            );
        } catch (QueryException $exception) {
            if ($this->isMissingCanonicalTable($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    private function resolve(StatisticalPublicSeriesPage $page, object $activeVersion): ?PublicClassifierContext
    {
        $mappedNode = $this->mappedCurrentNode($page, (int) $activeVersion->id);
        if ($mappedNode === null) {
            return null;
        }

        $versionId = (int) $mappedNode->classifier_version_id;
        $currentNodeId = (int) $mappedNode->id;
        $lineageRows = $this->lineage($versionId, $currentNodeId);
        if ($lineageRows === []) {
            return null;
        }

        $childRows = $this->children($versionId, $currentNodeId);
        $hasMoreChildren = count($childRows) > self::CHILD_LIMIT;
        $childRows = array_slice($childRows, 0, self::CHILD_LIMIT);

        $destinations = [];
        foreach ($childRows as $childRow) {
            if ($childRow->statistical_slug !== null) {
                $destinations[(int) $childRow->id] = (string) $childRow->statistical_slug;
            }
        }

        $lineage = array_map(
            fn (object $row): PublicClassifierPosition => $this->position($row, $currentNodeId, []),
            $lineageRows,
        );
        $children = array_map(
            fn (object $row): PublicClassifierPosition => $this->position($row, $currentNodeId, $destinations),
            $childRows,
        );
        $current = null;
        foreach ($lineage as $position) {
            if ($position->isCurrent) {
                $current = $position;
                break;
            }
        }

        if ($current === null) {
            return null;
        }

        return new PublicClassifierContext(
            (string) $activeVersion->version_label,
            CarbonImmutable::parse((string) $activeVersion->effective_from),
            $current,
            $lineage,
            $children,
            $hasMoreChildren,
        );
    }

    private function activeVersion(): ?object
    {
        return $this->database->table('statistical_classifiers as classifiers')
            ->join('statistical_classifier_active_versions as active_versions', 'active_versions.classifier_id', '=', 'classifiers.id')
            ->join('statistical_classifier_versions as versions', function ($join): void {
                $join->on('versions.id', '=', 'active_versions.classifier_version_id')
                    ->on('versions.classifier_id', '=', 'classifiers.id');
            })
            ->where('classifiers.code', 'okpd2')
            ->select([
                'versions.id',
                'versions.version_label',
                'versions.effective_from',
            ])
            ->first();
    }

    private function mappedCurrentNode(StatisticalPublicSeriesPage $page, int $activeVersionId): ?object
    {
        return $this->database->table('statistical_public_series_pages as pages')
            ->join('statistical_classifier_item_mappings as mappings', function ($join): void {
                $join->on('mappings.statistical_classifier_item_id', '=', 'pages.classifier_item_id')
                    ->where('mappings.review_status', 'confirmed')
                    ->whereIn('mappings.mapping_type', self::PUBLIC_MAPPING_TYPES)
                    ->whereNotNull('mappings.classifier_node_id');
            })
            ->join('statistical_classifier_active_versions as active_versions', function ($join): void {
                $join->on('active_versions.classifier_version_id', '=', 'mappings.classifier_version_id');
            })
            ->join('statistical_classifiers as classifiers', function ($join): void {
                $join->on('classifiers.id', '=', 'active_versions.classifier_id')
                    ->where('classifiers.code', 'okpd2');
            })
            ->join('statistical_classifier_nodes as nodes', function ($join): void {
                $join->on('nodes.id', '=', 'mappings.classifier_node_id')
                    ->on('nodes.classifier_version_id', '=', 'mappings.classifier_version_id');
            })
            ->where('pages.id', $page->getKey())
            ->where('pages.is_indexable', true)
            ->where('mappings.classifier_version_id', $activeVersionId)
            ->select([
                'nodes.id',
                'nodes.classifier_version_id',
            ])
            ->first();
    }

    /** @return list<object> */
    private function lineage(int $versionId, int $currentNodeId): array
    {
        return $this->database->select(<<<'SQL'
            WITH RECURSIVE classifier_lineage AS (
                SELECT
                    nodes.id,
                    nodes.classifier_version_id,
                    nodes.code,
                    nodes.name,
                    nodes.parent_node_id,
                    nodes.source_order,
                    0 AS lineage_depth
                FROM statistical_classifier_nodes AS nodes
                WHERE nodes.classifier_version_id = ?
                    AND nodes.id = ?

                UNION ALL

                SELECT
                    parents.id,
                    parents.classifier_version_id,
                    parents.code,
                    parents.name,
                    parents.parent_node_id,
                    parents.source_order,
                    classifier_lineage.lineage_depth + 1
                FROM statistical_classifier_nodes AS parents
                INNER JOIN classifier_lineage
                    ON classifier_lineage.parent_node_id = parents.id
                    AND classifier_lineage.classifier_version_id = parents.classifier_version_id
                WHERE classifier_lineage.lineage_depth < ?
            )
            SELECT *
            FROM classifier_lineage
            ORDER BY lineage_depth DESC
            SQL, [$versionId, $currentNodeId, self::MAX_LINEAGE_DEPTH]);
    }

    /** @return list<object> */
    private function children(int $versionId, int $currentNodeId): array
    {
        return $this->database->table('statistical_classifier_nodes as children')
            ->join('statistical_classifier_versions as versions', 'versions.id', '=', 'children.classifier_version_id')
            ->join('statistical_classifiers as classifiers', function ($join): void {
                $join->on('classifiers.id', '=', 'versions.classifier_id')
                    ->where('classifiers.code', 'okpd2');
            })
            ->join('statistical_classifier_active_versions as active_versions', function ($join): void {
                $join->on('active_versions.classifier_id', '=', 'classifiers.id')
                    ->on('active_versions.classifier_version_id', '=', 'versions.id');
            })
            ->leftJoin('statistical_classifier_item_mappings as child_mappings', function ($join) use ($versionId): void {
                $join->on('child_mappings.classifier_node_id', '=', 'children.id')
                    ->where('child_mappings.classifier_version_id', $versionId)
                    ->where('child_mappings.review_status', 'confirmed')
                    ->whereIn('child_mappings.mapping_type', self::PUBLIC_MAPPING_TYPES);
            })
            ->leftJoin('statistical_public_series_pages as child_pages', function ($join): void {
                $join->on('child_pages.classifier_item_id', '=', 'child_mappings.statistical_classifier_item_id')
                    ->where('child_pages.is_indexable', true)
                    ->whereNotNull('child_pages.slug');
            })
            ->where('children.classifier_version_id', $versionId)
            ->where('children.parent_node_id', $currentNodeId)
            ->select([
                'children.id',
                'children.classifier_version_id',
                'children.code',
                'children.name',
                'children.parent_node_id',
                'children.source_order',
            ])
            ->selectRaw('MIN(child_pages.slug) AS statistical_slug')
            ->groupBy([
                'children.id',
                'children.classifier_version_id',
                'children.code',
                'children.name',
                'children.parent_node_id',
                'children.source_order',
            ])
            ->orderByRaw('children.source_order IS NULL')
            ->orderBy('children.source_order')
            ->orderBy('children.code')
            ->limit(self::CHILD_LIMIT + 1)
            ->get()
            ->all();
    }

    /** @param array<int, string> $destinations */
    private function position(object $row, int $currentNodeId, array $destinations): PublicClassifierPosition
    {
        $nodeId = (int) $row->id;
        $slug = $destinations[$nodeId] ?? null;

        return new PublicClassifierPosition(
            (string) $row->code,
            (string) $row->name,
            $nodeId === $currentNodeId,
            $slug !== null,
            $slug,
        );
    }

    private function isMissingCanonicalTable(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '42S02' && $driverCode === 1146;
    }
}
