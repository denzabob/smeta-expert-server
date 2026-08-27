<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\GetPublicIndexFamilyOverview;
use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexFamilyController extends Controller
{
    public function __invoke(
        string $family,
        GetPublicIndexFamilyOverview $overview,
        PublicIndexFamilyRegistry $families,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
    ): View {
        $descriptor = $families->get($family);
        $isConsumer = $descriptor->code === PublicIndexFamilyRegistry::CONSUMER_PRICES;
        $canonical = $isConsumer ? $urls->consumerPrices() : $urls->producerPrices();
        $title = $isConsumer
            ? 'Индекс потребительских цен (ИПЦ) Росстата — динамика и расчёт | ПРИЗМА'
            : 'Индексы цен производителей Росстата | ПРИЗМА';
        $description = $isConsumer
            ? 'Официальные данные Росстата об индексе потребительских цен: динамика с 1991 года, график и расчёт изменения за выбранный период.'
            : 'Индексы цен производителей Росстата по продукции и товарным группам: доступные ряды, период данных и переход к опубликованному каталогу.';
        $summary = $overview->execute($descriptor->code, $isConsumer ? 4 : 6);

        return view($isConsumer ? 'price-indices.public.consumer-prices' : 'price-indices.public.producer-prices', [
            'summary' => $summary,
            'family' => $descriptor,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $canonical,
            'title' => $title,
            'description' => $description,
            'structuredData' => $structuredData->familyLanding($title, $description, $canonical, $descriptor, $urls),
        ]);
    }
}
