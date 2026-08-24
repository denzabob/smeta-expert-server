<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\GetPublicIndexFamilyOverview;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexFamilyController extends Controller
{
    public function __invoke(
        GetPublicIndexFamilyOverview $overview,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
    ): View {
        $canonical = $urls->producerPrices();
        $title = 'Индексы цен производителей Росстата | ПРИЗМА';
        $description = 'Индексы цен производителей Росстата по продукции и товарным группам: доступные ряды, период данных и переход к опубликованному каталогу.';
        $summary = $overview->execute(6);

        return view('price-indices.public.producer-prices', [
            'summary' => $summary,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $canonical,
            'title' => $title,
            'description' => $description,
            'structuredData' => $structuredData->familyLanding($title, $description, $canonical, $urls),
        ]);
    }
}
