<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\ListPublicIndexPages;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexCatalogController extends Controller
{
    public function __invoke(
        ListPublicIndexPages $pages,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
    ): View {
        $catalog = $pages->execute();
        $catalog->withPath($urls->catalog());
        $currentPage = $catalog->currentPage();
        if ($currentPage > $catalog->lastPage()) {
            abort(404);
        }
        $latestDataYear = $pages->latestDataYear();

        $year = $latestDataYear === null ? '' : ' '.$latestDataYear;
        $title = $currentPage > 1
            ? "Индексы цен Росстата{$year} — страница {$currentPage} | ПРИЗМА"
            : "Индексы цен Росстата{$year} — индексы цен производителей | ПРИЗМА";
        $descriptionYear = $latestDataYear === null ? '' : " на {$latestDataYear} год";
        $description = "Индексы цен производителей Росстата по товарам{$descriptionYear}: динамика цен, месячные индексы и коэффициенты изменения стоимости. Официальные статистические данные.";
        if ($currentPage > 1) {
            $description .= ' Страница '.$currentPage.'.';
        }

        return view('price-indices.public.catalog', [
            'pages' => $catalog,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $urls->catalog($currentPage),
            'title' => $title,
            'description' => $description,
            'latestDataYear' => $latestDataYear,
            'structuredData' => $structuredData->catalog($title, $description, $urls),
        ]);
    }
}
