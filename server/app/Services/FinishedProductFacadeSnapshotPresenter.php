<?php

namespace App\Services;

use Carbon\Carbon;

class FinishedProductFacadeSnapshotPresenter
{
    private const METHOD_LABELS = [
        'mean' => 'Среднее',
        'median' => 'Медиана',
        'trimmed_mean' => 'Усечённое среднее',
        'single' => 'Один источник',
    ];

    private const SOURCE_KIND_LABELS = [
        'price_list_row' => 'Строка прайс-листа',
        'price_document' => 'Документ',
        'url_capture' => 'URL/скриншот',
        'manual_entry' => 'Ручной ввод',
    ];

    private const STATUS_LABELS = [
        'active' => 'Активный',
        'inactive' => 'Неактивный',
        'stale' => 'Устаревший',
        'invalid' => 'Некорректный',
        'superseded' => 'Заменён',
    ];

    private const ASSET_TYPE_LABELS = [
        'screenshot' => 'Скриншот',
        'file' => 'Файл',
        'image' => 'Изображение',
        'link' => 'Ссылка',
    ];

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function presentFromJustificationSummary(array $summary): array
    {
        $identity = $this->buildIdentity(
            (string) ($summary['specification_name'] ?? $summary['name'] ?? 'Фасад'),
            $summary['article'] ?? null,
            (array) ($summary['facade_characteristics'] ?? [])
        );

        $pricingBasis = (array) ($summary['pricing_basis'] ?? []);
        $sourceLevelSnapshot = is_array($summary['source_level_snapshot'] ?? null)
            ? $summary['source_level_snapshot']
            : [];

        $pricingSummary = $this->buildPricingSummary($pricingBasis, $sourceLevelSnapshot);
        $sources = $this->buildSources((array) ($sourceLevelSnapshot['sources'] ?? []));
        $positionSummary = $this->buildPositionSummary((array) ($summary['position_summary'] ?? []));

        return [
            'facade_identity' => $identity,
            'pricing_summary' => $pricingSummary,
            'position_summary' => $positionSummary,
            'sources' => $sources,
            'basis_note' => $summary['basis_note'] ?? $this->defaultBasisNote($pricingSummary['materialization_status'] ?? null),
            'compact_summary_text' => $this->buildCompactSummaryText($pricingSummary),
            'quotes' => array_map(fn (array $source) => $source['quote'], $sources),
        ];
    }

    /**
     * @param  array<string, mixed>  $facade
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    public function presentFromReportFacadeDetail(array $facade, array $detail): array
    {
        return $this->presentFromJustificationSummary([
            'specification_name' => $facade['name'] ?? 'Фасад',
            'article' => $facade['article'] ?? null,
            'facade_characteristics' => [
                'base_type' => $facade['base_material_label'] ?? null,
                'thickness_mm' => $facade['thickness_mm'] ?? null,
                'covering' => $facade['finish_type'] ?? null,
                'cover_type' => $facade['finish_type_label'] ?? null,
                'collection' => $facade['finish_name'] ?? null,
                'decor_label' => $facade['decor_label'] ?? null,
            ],
            'pricing_basis' => [
                'pricing_basis' => $detail['pricing_basis'] ?? 'finished_product_snapshot',
                'computed_price_per_m2' => $detail['price_per_m2'] ?? null,
                'aggregation_method' => $detail['price_method'] ?? null,
                'source_count' => $detail['price_sources_count'] ?? null,
                'min_price' => $detail['price_min'] ?? null,
                'max_price' => $detail['price_max'] ?? null,
                'captured_at' => $detail['pricing_snapshot_captured_at'] ?? null,
                'computed_at' => $detail['pricing_computed_at'] ?? null,
            ],
            'position_summary' => [
                'project_position_id' => $detail['position_id'] ?? $detail['id'] ?? null,
                'detail_name' => $detail['detail_type'] ?? 'Фасад',
                'quantity' => $detail['quantity'] ?? 1,
                'width_mm' => $detail['width'] ?? null,
                'height_mm' => $detail['length'] ?? null,
                'area_m2' => $detail['area_m2'] ?? null,
                'total_cost' => $detail['total_cost'] ?? null,
            ],
            'basis_note' => 'Цена за м² отражается по immutable pricing snapshot, сохранённому вместе с фасадной позицией.',
            'source_level_snapshot' => is_array($detail['source_level_snapshot'] ?? null)
                ? $detail['source_level_snapshot']
                : [],
        ]);
    }

    /**
     * @param  string  $displayName
     * @param  mixed  $article
     * @param  array<string, mixed>  $characteristics
     * @return array<string, mixed>
     */
    private function buildIdentity(string $displayName, mixed $article, array $characteristics): array
    {
        return [
            'display_name' => $displayName,
            'article' => $article,
            'characteristics' => $characteristics,
            'characteristics_text' => $this->buildCharacteristicsText($characteristics),
        ];
    }

    /**
     * @param  array<string, mixed>  $pricingBasis
     * @param  array<string, mixed>  $sourceLevelSnapshot
     * @return array<string, mixed>
     */
    private function buildPricingSummary(array $pricingBasis, array $sourceLevelSnapshot): array
    {
        $materializationStatus = $sourceLevelSnapshot['materialization_status']
            ?? (!empty($sourceLevelSnapshot['sources']) ? 'captured' : 'summary_only');

        return [
            'computed_price_per_m2' => $this->toFloatOrNull($pricingBasis['computed_price_per_m2'] ?? null),
            'computed_price_per_m2_display' => $this->formatMoney($pricingBasis['computed_price_per_m2'] ?? null, 'руб./м²'),
            'aggregation_method' => $pricingBasis['aggregation_method'] ?? null,
            'aggregation_method_label' => self::METHOD_LABELS[$pricingBasis['aggregation_method'] ?? '']
                ?? ($pricingBasis['aggregation_method'] ?? '—'),
            'source_count' => isset($pricingBasis['source_count']) ? (int) $pricingBasis['source_count'] : null,
            'min_price' => $this->toFloatOrNull($pricingBasis['min_price'] ?? null),
            'max_price' => $this->toFloatOrNull($pricingBasis['max_price'] ?? null),
            'range_display' => $this->formatRange($pricingBasis['min_price'] ?? null, $pricingBasis['max_price'] ?? null),
            'captured_at' => $pricingBasis['captured_at'] ?? null,
            'captured_at_display' => $this->formatDateTime($pricingBasis['captured_at'] ?? null),
            'computed_at' => $pricingBasis['computed_at'] ?? null,
            'computed_at_display' => $this->formatDateTime($pricingBasis['computed_at'] ?? null),
            'materialization_status' => $materializationStatus,
            'materialization_status_label' => $materializationStatus === 'captured'
                ? 'Source-level snapshot зафиксирован'
                : 'Доступен только summary-level snapshot',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function buildSources(array $sources): array
    {
        return array_values(array_map(function (array $source): array {
            $evidenceAssets = array_values(array_map(
                fn (array $asset): array => $this->buildEvidenceAsset($asset),
                (array) ($source['evidence_assets'] ?? [])
            ));

            $supplierName = data_get($source, 'supplier.name') ?? '—';
            $normalizedPrice = $this->toFloatOrNull($source['price_per_m2_normalized'] ?? null);
            $sourceKind = $source['source_kind'] ?? null;
            $status = $source['status'] ?? null;

            return [
                'supplier_name' => $supplierName,
                'normalized_price_per_m2' => $normalizedPrice,
                'normalized_price_per_m2_display' => $this->formatMoney($normalizedPrice, 'руб./м²'),
                'source_kind' => $sourceKind,
                'source_kind_label' => self::SOURCE_KIND_LABELS[$sourceKind] ?? ($sourceKind ?? 'Источник'),
                'status' => $status,
                'status_label' => self::STATUS_LABELS[$status] ?? ($status ?? '—'),
                'effective_date' => $source['effective_date'] ?? null,
                'effective_date_display' => $this->formatDate($source['effective_date'] ?? null),
                'captured_at' => $source['captured_at'] ?? null,
                'captured_at_display' => $this->formatDateTime($source['captured_at'] ?? null),
                'article' => $source['article'] ?? null,
                'category' => $source['category'] ?? null,
                'description' => $source['description'] ?? null,
                'notes' => $source['notes'] ?? null,
                'evidence_assets_count' => isset($source['evidence_assets_count']) ? (int) $source['evidence_assets_count'] : count($evidenceAssets),
                'evidence_assets' => $evidenceAssets,
                'list_label' => $this->buildSourceListLabel($supplierName, $normalizedPrice, $sourceKind, $source['effective_date'] ?? null, isset($source['evidence_assets_count']) ? (int) $source['evidence_assets_count'] : count($evidenceAssets)),
                'quote' => $this->buildQuoteCompatibility($source, $evidenceAssets),
            ];
        }, $sources));
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    private function buildEvidenceAsset(array $asset): array
    {
        $assetType = $asset['asset_type'] ?? null;

        return [
            'asset_type' => $assetType,
            'asset_type_label' => self::ASSET_TYPE_LABELS[$assetType] ?? ($assetType ?? 'Вложение'),
            'display_label' => $asset['display_label'] ?? $asset['original_name'] ?? $asset['source_url'] ?? '—',
            'original_name' => $asset['original_name'] ?? null,
            'mime_type' => $asset['mime_type'] ?? null,
            'file_size' => $asset['file_size'] ?? null,
            'source_url' => $asset['source_url'] ?? null,
            'captured_at' => $asset['captured_at'] ?? null,
            'captured_at_display' => $this->formatDateTime($asset['captured_at'] ?? null),
            'access_kind' => $asset['access_kind'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $positionSummary
     * @return array<string, mixed>
     */
    private function buildPositionSummary(array $positionSummary): array
    {
        return [
            ...$positionSummary,
            'area_m2_display' => isset($positionSummary['area_m2'])
                ? number_format((float) $positionSummary['area_m2'], 2, ',', ' ')
                : null,
            'total_cost_display' => $this->formatMoney($positionSummary['total_cost'] ?? null, 'руб.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<int, array<string, mixed>>  $evidenceAssets
     * @return array<string, mixed>
     */
    private function buildQuoteCompatibility(array $source, array $evidenceAssets): array
    {
        $externalAsset = collect($evidenceAssets)->first(fn (array $asset) => !empty($asset['source_url']));
        $namedAsset = collect($evidenceAssets)->first(fn (array $asset) => !empty($asset['original_name']));

        return [
            'supplier_id' => data_get($source, 'supplier.id'),
            'supplier_name' => data_get($source, 'supplier.name') ?? '—',
            'price_list_version_id' => data_get($source, 'source_ref.price_list_version_id'),
            'price_per_m2' => $source['price_per_m2_normalized'] ?? null,
            'price_list_name' => $source['description'] ?: ($source['category'] ?: ($source['source_kind'] ?? 'Источник')),
            'version_number' => null,
            'source_type' => $source['source_kind'] ?? null,
            'source_url' => $externalAsset['source_url'] ?? null,
            'original_filename' => $namedAsset['original_name'] ?? null,
            'sha256' => $this->firstNonEmpty($evidenceAssets, 'content_hash'),
            'effective_date' => $source['effective_date'] ?? null,
            'captured_at' => $source['captured_at'] ?? null,
            'supplier_article' => $source['article'] ?? null,
            'supplier_category' => $source['category'] ?? null,
            'supplier_description' => $source['description'] ?? null,
            'evidence_assets' => $evidenceAssets,
        ];
    }

    /**
     * @param  array<string, mixed>  $pricingSummary
     */
    private function buildCompactSummaryText(array $pricingSummary): string
    {
        $parts = [];

        if (!empty($pricingSummary['aggregation_method_label'])) {
            $parts[] = $pricingSummary['aggregation_method_label'];
        }

        if (isset($pricingSummary['source_count'])) {
            $parts[] = $pricingSummary['source_count'] . ' источников';
        }

        if (!empty($pricingSummary['range_display'])) {
            $parts[] = 'диапазон ' . $pricingSummary['range_display'];
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $characteristics
     */
    private function buildCharacteristicsText(array $characteristics): ?string
    {
        $parts = [];

        if (!empty($characteristics['base_type'])) {
            $parts[] = 'Основа: ' . $characteristics['base_type'];
        }

        if (!empty($characteristics['thickness_mm'])) {
            $parts[] = 'Толщина: ' . $characteristics['thickness_mm'] . ' мм';
        }

        if (!empty($characteristics['covering'])) {
            $parts[] = 'Покрытие: ' . $characteristics['covering'];
        }

        if (!empty($characteristics['cover_type'])) {
            $parts[] = 'Тип: ' . $characteristics['cover_type'];
        }

        if (!empty($characteristics['decor_label'])) {
            $parts[] = 'Декор: ' . $characteristics['decor_label'];
        }

        return $parts === [] ? null : implode('. ', $parts) . '.';
    }

    private function formatMoney(mixed $value, string $suffix): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, ',', ' ') . ' ' . $suffix;
    }

    private function formatRange(mixed $min, mixed $max): ?string
    {
        if ($min === null || $max === null || $min === '' || $max === '') {
            return null;
        }

        return number_format((float) $min, 2, ',', ' ')
            . ' – '
            . number_format((float) $max, 2, ',', ' ')
            . ' руб./м²';
    }

    private function formatDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d.m.Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatDateTime(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function defaultBasisNote(?string $materializationStatus): string
    {
        return $materializationStatus === 'captured'
            ? 'Цена за м² подтверждена immutable snapshot с сохранёнными source-level данными и вложениями.'
            : 'Цена за м² подтверждена immutable pricing snapshot позиции; source-level вложения не были материализованы и не реконструируются из legacy-источников.';
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function firstNonEmpty(array $items, string $key): mixed
    {
        $item = collect($items)->first(fn (array $entry) => !empty($entry[$key]));

        return $item[$key] ?? null;
    }

    private function buildSourceListLabel(
        string $supplierName,
        ?float $normalizedPrice,
        ?string $sourceKind,
        ?string $effectiveDate,
        int $evidenceAssetsCount
    ): string {
        $parts = [$supplierName];

        if ($normalizedPrice !== null) {
            $parts[] = number_format($normalizedPrice, 2, ',', ' ') . ' руб./м²';
        }

        if ($sourceKind) {
            $parts[] = self::SOURCE_KIND_LABELS[$sourceKind] ?? $sourceKind;
        }

        if ($effectiveDate) {
            $parts[] = $this->formatDate($effectiveDate) ?? $effectiveDate;
        }

        if ($evidenceAssetsCount > 0) {
            $parts[] = 'вложений: ' . $evidenceAssetsCount;
        }

        return implode(' · ', $parts);
    }
}
