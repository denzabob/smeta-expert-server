<?php

namespace App\Services;

use App\Evidence\CostDriverType;
use App\Models\ProjectPosition;

class FinishedProductFacadeRevisionRowAssembler
{
    public function __construct(
        private FinishedProductPositionSnapshotReader $finishedProductPositionSnapshotReader,
    ) {}

    /**
     * Summary-level immutable contract used by evidence/report builders.
     *
     * @return array<string, mixed>
     */
    public function buildSnapshotSummary(ProjectPosition $position): array
    {
        $data = $this->finishedProductPositionSnapshotReader->read($position);
        $areaM2 = (($position->width ?? 0) / 1000) * (($position->length ?? 0) / 1000) * ($position->quantity ?? 0);
        $totalCost = $areaM2 * (float) ($data['price_per_m2'] ?? 0);

        return [
            'reference_type' => 'snapshot_summary',
            'finished_product_specification_id' => $data['reference_id'],
            'specification_name' => $data['name'],
            'article' => $data['article'],
            'facade_characteristics' => [
                'facade_class' => $data['facade_class'],
                'base_type' => $data['base_type'],
                'thickness_mm' => $data['thickness_mm'],
                'covering' => $data['covering'],
                'cover_type' => $data['cover_type'],
                'collection' => $data['collection'],
                'decor_label' => $data['decor_label'],
                'price_group_label' => $data['price_group_label'],
            ],
            'pricing_basis' => [
                'pricing_basis' => 'finished_product_snapshot',
                'computed_price_per_m2' => $data['price_per_m2'],
                'aggregation_method' => $data['price_method'],
                'source_count' => $data['price_sources_count'],
                'min_price' => $data['price_min'],
                'max_price' => $data['price_max'],
                'captured_at' => $data['captured_at'],
                'computed_at' => $data['computed_at'],
            ],
            'position_summary' => [
                'project_position_id' => $position->id,
                'detail_name' => $position->custom_name ?? 'Фасад',
                'quantity' => $position->quantity ?? 1,
                'width_mm' => $position->width ?? 0,
                'height_mm' => $position->length ?? 0,
                'area_m2' => $areaM2,
                'total_cost' => $totalCost,
            ],
            'basis_note' => 'Цена за м² зафиксирована в immutable pricing snapshot позиции и отражает сохранённый агрегированный basis без legacy quote-строк.',
            'source_level_snapshot' => $data['source_level_snapshot'] ?? null,
        ];
    }

    /**
     * Full revision/report row for price justification snapshot storage.
     *
     * @return array<string, mixed>
     */
    public function buildRevisionReportRow(ProjectPosition $position): array
    {
        $summary = $this->buildSnapshotSummary($position);
        $pricingBasis = (array) ($summary['pricing_basis'] ?? []);

        return [
            'project_position_id' => $position->id,
            'project_fitting_id' => null,
            'material_id' => null,
            'name' => $summary['specification_name'] ?? ('Фасад #' . $position->id),
            'article' => $summary['article'] ?? null,
            'unit' => 'м²',
            'material_type' => null,
            'price_history_id' => null,
            'source_url' => null,
            'observed_at' => $pricingBasis['captured_at'] ?? null,
            'screenshot_path' => null,
            'price_per_unit' => $pricingBasis['computed_price_per_m2'] ?? null,
            'currency' => 'RUB',
            'true_score' => null,
            'source_type' => 'snapshot_summary',
            'capture_source' => null,
            'cost_driver_type' => CostDriverType::FACADE,
            'source_domain' => null,
            ...$summary,
        ];
    }

    /**
     * Normalize a stored snapshot facade revision/report row for downstream readers.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalizeStoredRevisionReportRow(array $row): array
    {
        $materialId = $row['material_id'] ?? null;

        return [
            'project_position_id' => $row['project_position_id'] ?? null,
            'project_fitting_id' => $row['project_fitting_id'] ?? null,
            'material_id' => $materialId,
            'name' => $row['name'] ?? ('Материал #' . ($materialId ?: '—')),
            'article' => $row['article'] ?? null,
            'unit' => $row['unit'] ?? null,
            'material_type' => $row['material_type'] ?? null,
            'price_per_unit' => $row['price_per_unit'] ?? null,
            'currency' => $row['currency'] ?? 'RUB',
            'source_url' => $row['source_url'] ?? null,
            'observed_at' => $row['observed_at'] ?? null,
            'screenshot_path' => $row['screenshot_path'] ?? null,
            'true_score' => $row['true_score'] ?? null,
            'source_type' => $row['source_type'] ?? null,
            'capture_source' => $row['capture_source'] ?? null,
            'cost_driver_type' => $row['cost_driver_type'] ?? null,
            'source_domain' => $row['source_domain'] ?? null,
            'reference_type' => $row['reference_type'] ?? null,
            'finished_product_specification_id' => $row['finished_product_specification_id'] ?? null,
            'specification_name' => $row['specification_name'] ?? null,
            'facade_characteristics' => is_array($row['facade_characteristics'] ?? null) ? $row['facade_characteristics'] : [],
            'pricing_basis' => is_array($row['pricing_basis'] ?? null) ? $row['pricing_basis'] : [],
            'position_summary' => is_array($row['position_summary'] ?? null) ? $row['position_summary'] : [],
            'basis_note' => $row['basis_note'] ?? null,
            'source_level_snapshot' => is_array($row['source_level_snapshot'] ?? null) ? $row['source_level_snapshot'] : [],
        ];
    }
}
