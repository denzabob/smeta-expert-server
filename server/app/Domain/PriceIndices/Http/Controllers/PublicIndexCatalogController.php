<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\ListPublicIndexPages;
use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Services\SearchPublicIndexPages;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class PublicIndexCatalogController extends Controller
{
    public function __invoke(
        Request $request,
        ListPublicIndexPages $pages,
        SearchPublicIndexPages $search,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
        PublicIndexFamilyRegistry $families,
    ): View {
        $isSearch = $request->query->has('q');
        $rawQuery = $request->query('q');
        $searchQuery = is_string($rawQuery) ? $pages->normalizedQuery($rawQuery) : '';
        $isCombinedSearch = $isSearch && $searchQuery !== '';
        $catalog = $isCombinedSearch
            ? $search->execute($searchQuery)
            : $pages->execute($isSearch ? $searchQuery : null);
        $catalog->withPath($urls->catalog());
        $currentPage = $catalog->currentPage();
        if ($currentPage > $catalog->lastPage()) {
            abort(404);
        }
        $latestDataYear = $pages->latestDataYear();

        $year = $latestDataYear === null ? '' : ' '.$latestDataYear;
        $title = $isSearch
            ? 'Поиск по данным Росстата и ОКПД2'.($searchQuery === '' ? '' : ': '.$searchQuery).' | ПРИЗМА'
            : ($currentPage > 1
                ? "Индексы цен Росстата{$year} — страница {$currentPage} | ПРИЗМА"
                : "Индексы цен Росстата{$year} — производители и потребители | ПРИЗМА");
        $descriptionYear = $latestDataYear === null ? '' : " на {$latestDataYear} год";
        $description = "Индексы цен Росстата{$descriptionYear}: цены производителей и индекс потребительских цен, месячная динамика и расчёт изменения за период.";
        if ($currentPage > 1) {
            $description .= ' Страница '.$currentPage.'.';
        }

        return view('price-indices.public.catalog', [
            'pages' => $catalog,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $isSearch ? $urls->catalog() : $urls->catalog($currentPage),
            'robots' => $isSearch ? 'noindex,follow' : 'index,follow',
            'title' => $title,
            'description' => $description,
            'latestDataYear' => $latestDataYear,
            'structuredData' => $isSearch ? null : $structuredData->catalog($title, $description, $urls),
            'isSearch' => $isSearch,
            'searchQuery' => $searchQuery,
            'isCombinedSearch' => $isCombinedSearch,
            'families' => $families,
        ]);
    }
}
