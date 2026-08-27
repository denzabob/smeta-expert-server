<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;

final class GetPublicIndexPage
{
    public function __construct(private readonly PublicIndexFamilyRegistry $families) {}

    public function execute(string $familyCode, string $slug): StatisticalPublicSeriesPage
    {
        $page = StatisticalPublicSeriesPage::query()
            ->leftJoin('statistical_series as public_series', 'public_series.id', '=', 'statistical_public_series_pages.series_id')
            ->leftJoin('statistical_indicators as public_indicators', 'public_indicators.id', '=', 'public_series.indicator_id')
            ->leftJoin('statistical_territories as public_territories', 'public_territories.id', '=', 'public_series.territory_id')
            ->select([
                'statistical_public_series_pages.*',
                'public_indicators.name as structured_indicator_name',
                'public_territories.code as structured_territory_code',
                'public_territories.name as structured_territory_name',
                'public_series.public_id as structured_series_public_id',
                'public_series.frequency as structured_series_frequency',
                'public_series.comparison_basis as structured_series_comparison_basis',
                'public_series.unit as structured_series_unit',
            ])
            ->where('slug', $slug)
            ->where('is_indexable', true)
            ->with([
                'dataset:id,code,name,provider_name,classifier_code',
                'import:id,public_id,importer_code,importer_version,published_at,metadata_json',
                'classifierItem:id,classifier_code,item_code,name,metadata_json',
                'sourceFile:id,source_id,original_filename,sha256,source_url',
                'sourceFile.source:id,name,source_page_url',
            ])
            ->firstOrFail();

        if ($this->families->forDataset($page->dataset)->code !== $familyCode) {
            abort(404);
        }

        $page->setRelation('series', (new StatisticalSeries)->newFromBuilder([
            'id' => $page->series_id,
            'public_id' => $page->getAttribute('structured_series_public_id'),
            'frequency' => $page->getAttribute('structured_series_frequency'),
            'comparison_basis' => $page->getAttribute('structured_series_comparison_basis'),
            'unit' => $page->getAttribute('structured_series_unit'),
        ]));

        return $page;
    }
}
