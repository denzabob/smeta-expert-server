<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\GetPublicIndexPage;
use App\Domain\PriceIndices\Application\Services\ListPublicIndexObservations;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexDetailController extends Controller
{
    public function __invoke(
        string $slug,
        GetPublicIndexPage $pages,
        ListPublicIndexObservations $observations,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
    ): View {
        $page = $pages->execute($slug);
        $item = $page->classifierItem;
        $provider = $page->dataset?->provider_name ?: 'Росстат';
        $change = $formatter->percent($page->change_percent);
        $coefficient = $formatter->coefficient($page->coefficient);
        $canonical = $urls->detail($slug);
        $heading = $formatter->heading((string) $item?->name);
        $title = $formatter->detailTitle((string) $item?->name);
        $description = $formatter->description(
            (string) $item?->name,
            $page->period_from,
            $page->period_to,
            $change,
            $coefficient,
            $provider,
        );

        return view('price-indices.public.detail', [
            'page' => $page,
            'observations' => $observations->execute($page),
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $canonical,
            'calculatorUrl' => $urls->calculator((string) $page->series?->public_id, (string) $item?->item_code),
            'title' => $title,
            'description' => $description,
            'structuredData' => $structuredData->detail($page, $canonical, $heading, $description, $urls),
        ]);
    }
}
