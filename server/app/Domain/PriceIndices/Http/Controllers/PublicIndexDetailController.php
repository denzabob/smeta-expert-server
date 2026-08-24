<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\GetPublicIndexPage;
use App\Domain\PriceIndices\Application\Services\ListPublicIndexObservations;
use App\Domain\PriceIndices\Application\Services\ListRelatedPublicIndexPages;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexDetailController extends Controller
{
    public function __invoke(
        string $slug,
        GetPublicIndexPage $pages,
        ListPublicIndexObservations $observations,
        ListRelatedPublicIndexPages $relatedPages,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
    ): View {
        $page = $pages->execute($slug);
        $item = $page->classifierItem;
        $provider = $page->dataset?->provider_name ?: 'Росстат';
        $change = $formatter->percent($page->change_percent);
        $coefficient = $formatter->coefficient($page->coefficient);
        $latestDataYear = (int) $page->period_to->format('Y');
        $canonical = $urls->detail($slug);
        $providerCodeKind = $item?->metadata_json['provider_code_kind'] ?? null;
        $classifierLabel = $formatter->classifierLabel(
            (string) $item?->classifier_code,
            is_string($providerCodeKind) ? $providerCodeKind : null,
        );
        $indicatorType = $formatter->indicatorType(
            (string) $page->getAttribute('structured_indicator_name'),
            $provider,
        );
        $heading = $formatter->heading(
            (string) $item?->item_code,
            (string) $item?->name,
            $classifierLabel,
        );
        $title = $formatter->detailTitle(
            (string) $item?->item_code,
            (string) $item?->name,
            $indicatorType,
            $classifierLabel,
        );
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
            'relatedPages' => $relatedPages->execute($page),
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $canonical,
            'calculatorUrl' => $urls->calculator((string) $page->series?->public_id, (string) $item?->item_code),
            'title' => $title,
            'description' => $description,
            'latestDataYear' => $latestDataYear,
            'heading' => $heading,
            'indicatorType' => $indicatorType,
            'change' => $change,
            'coefficient' => $coefficient,
            'structuredData' => $structuredData->detail($page, $canonical, $heading, $description, $urls),
        ]);
    }
}
