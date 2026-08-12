<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;

final class GetPublicIndexPage
{
    public function execute(string $slug): StatisticalPublicSeriesPage
    {
        return StatisticalPublicSeriesPage::query()
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
