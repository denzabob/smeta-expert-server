<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;

final class GetPublicIndexPage
{
    public function execute(string $slug): StatisticalPublicSeriesPage
    {
        return StatisticalPublicSeriesPage::query()
            ->leftJoin('statistical_series as public_series', 'public_series.id', '=', 'statistical_public_series_pages.series_id')
            ->leftJoin('statistical_indicators as public_indicators', 'public_indicators.id', '=', 'public_series.indicator_id')
            ->leftJoin('statistical_territories as public_territories', 'public_territories.id', '=', 'public_series.territory_id')
            ->select([
                'statistical_public_series_pages.*',
                'public_indicators.name as structured_indicator_name',
                'public_territories.code as structured_territory_code',
                'public_territories.name as structured_territory_name',
            ])
            ->where('slug', $slug)
            ->where('is_indexable', true)
            ->with([
                'dataset:id,name,provider_name',
                'import:id,public_id,importer_code,importer_version,published_at',
                'series:id,public_id',
                'classifierItem:id,item_code,name',
                'sourceFile:id,source_id,original_filename,sha256,source_url',
                'sourceFile.source:id,name,source_page_url',
            ])
            ->firstOrFail();
    }
}
