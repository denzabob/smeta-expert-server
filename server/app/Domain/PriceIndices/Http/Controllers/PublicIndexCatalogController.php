<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\ListPublicIndexPages;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexCatalogController extends Controller
{
    public function __invoke(
        ListPublicIndexPages $pages,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
    ): View {
        $catalog = $pages->execute();
        $catalog->withPath($urls->catalog());
        $currentPage = $catalog->currentPage();

        return view('price-indices.public.catalog', [
            'pages' => $catalog,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $urls->catalog($currentPage),
            'title' => 'Индексы цен производителей Росстата — ПРИЗМА'.($currentPage > 1 ? ' — страница '.$currentPage : ''),
            'description' => 'Официальные индексы цен производителей Росстата по товарам: периоды, коэффициенты и изменения для расчёта стоимости.',
        ]);
    }
}
