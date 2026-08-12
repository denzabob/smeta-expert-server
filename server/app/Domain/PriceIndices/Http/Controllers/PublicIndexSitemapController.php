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
        return response()->view('price-indices.public.sitemap', [
            'entries' => $entries->execute(),
            'urls' => $urls,
        ], 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
