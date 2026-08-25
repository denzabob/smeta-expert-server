<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Data\ClassifierItemMappingReport;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItemMapping;
use Illuminate\Support\Facades\DB;

final class ReportClassifierItemMappings
{
    public function __construct(private readonly ResolveClassifierMappingContext $context) {}

    public function execute(string $classifierCode, int $conflictLimit = 25): ClassifierItemMappingReport
    {
        $context = $this->context->execute($classifierCode);
        $version = $context['version'];
        $limit = max(1, min($conflictLimit, 100));
        $compatibleItems = $this->context->compatibleItemsQuery($context['aliases']);
        $mappingQuery = StatisticalClassifierItemMapping::query()
            ->where('classifier_version_id', $version->id)
            ->whereIn('statistical_classifier_item_id', (clone $compatibleItems)->select('statistical_classifier_items.id'));

        $mappingTypes = (clone $mappingQuery)
            ->select('mapping_type', DB::raw('COUNT(*) AS aggregate_count'))
            ->groupBy('mapping_type')
            ->pluck('aggregate_count', 'mapping_type')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $reviewStatuses = (clone $mappingQuery)
            ->select('review_status', DB::raw('COUNT(*) AS aggregate_count'))
            ->groupBy('review_status')
            ->pluck('aggregate_count', 'review_status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $manualDecisions = (clone $mappingQuery)
            ->where(function ($query): void {
                $query->whereNotLike('method', StatisticalClassifierItemMapping::AUTOMATIC_METHOD_PREFIX.'%')
                    ->orWhereNotNull('confirmed_by');
            })
            ->count();

        $conflicts = DB::table('statistical_classifier_item_mappings as mappings')
            ->join('statistical_classifier_items as local_items', 'local_items.id', '=', 'mappings.statistical_classifier_item_id')
            ->join('statistical_datasets as mapping_datasets', 'mapping_datasets.id', '=', 'local_items.dataset_id')
            ->leftJoin('statistical_classifier_nodes as nodes', function ($join): void {
                $join->on('nodes.id', '=', 'mappings.classifier_node_id')
                    ->on('nodes.classifier_version_id', '=', 'mappings.classifier_version_id');
            })
            ->where('mappings.classifier_version_id', $version->id)
            ->whereIn('mapping_datasets.classifier_code', $context['aliases'])
            ->whereIn('local_items.classifier_code', $context['aliases'])
            ->whereIn('mappings.mapping_type', ['ambiguous', 'unmapped'])
            ->orderBy('local_items.item_code')
            ->orderBy('local_items.id')
            ->limit($limit)
            ->get([
                'local_items.item_code as local_code',
                'local_items.name as local_name',
                'nodes.code as canonical_code',
                'nodes.name as canonical_name',
                'mappings.mapping_type',
                'mappings.review_status',
                'mappings.evidence_json',
            ])
            ->map(function ($row): array {
                $evidence = json_decode((string) $row->evidence_json, true);

                return [
                    'local_code' => (string) $row->local_code,
                    'local_name' => (string) $row->local_name,
                    'canonical_code' => $row->canonical_code === null ? null : (string) $row->canonical_code,
                    'canonical_name' => $row->canonical_name === null ? null : (string) $row->canonical_name,
                    'mapping_type' => (string) $row->mapping_type,
                    'review_status' => (string) $row->review_status,
                    'reason' => is_array($evidence) && is_string($evidence['reason'] ?? null)
                        ? $evidence['reason']
                        : 'not_recorded',
                ];
            })
            ->all();

        return new ClassifierItemMappingReport(
            $context['classifier']->code,
            $version->public_id,
            $version->version_label,
            (clone $compatibleItems)->count(),
            (clone $mappingQuery)->count(),
            $manualDecisions,
            $mappingTypes,
            $reviewStatuses,
            $conflicts,
            $limit,
        );
    }
}
