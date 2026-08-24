<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\ListPublicIndexSitemapEntries;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

final class PublicIndexSitemapController extends Controller
{
    public function __invoke(
        ListPublicIndexSitemapEntries $entries,
        PublicPriceIndexUrl $urls,
    ): Response {
        $sitemapEntries = $entries->execute();

        return response()->view('price-indices.public.sitemap', [
            'entries' => $sitemapEntries,
            'lastModifiedAt' => $sitemapEntries->max('generated_at'),
            'urls' => $urls,
        ], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
