<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\GetPublicIndexFamilyOverview;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexProductsController extends Controller
{
    public function __invoke(
        GetPublicIndexFamilyOverview $overview,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
    ): View {
        $canonical = $urls->producerPriceProducts();
        $title = 'Индексы цен производителей по товарам и товарным группам | ПРИЗМА';
        $description = 'Обзор опубликованных индексов цен производителей Росстата по товарам и товарным группам с переходом к отдельным статистическим рядам.';
        $summary = $overview->execute(12);

        return view('price-indices.public.producer-price-products', [
            'summary' => $summary,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $canonical,
            'title' => $title,
            'description' => $description,
            'structuredData' => $structuredData->productsLanding($title, $description, $canonical, $urls),
        ]);
    }
}
