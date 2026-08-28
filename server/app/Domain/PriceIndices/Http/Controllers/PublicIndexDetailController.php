<?php

namespace App\Domain\PriceIndices\Http\Controllers;

use App\Domain\PriceIndices\Application\Services\BuildPublicPriceIndexChartData;
use App\Domain\PriceIndices\Application\Services\GetPublicIndexPage;
use App\Domain\PriceIndices\Application\Services\ListPublicIndexObservations;
use App\Domain\PriceIndices\Application\Services\ListRelatedPublicIndexPages;
use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Services\ResolvePublicClassifierContext;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicIndexStructuredData;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

final class PublicIndexDetailController extends Controller
{
    public function __invoke(
        string $slug,
        string $family,
        GetPublicIndexPage $pages,
        ListPublicIndexObservations $observations,
        BuildPublicPriceIndexChartData $chartData,
        ListRelatedPublicIndexPages $relatedPages,
        ResolvePublicClassifierContext $classifierContexts,
        PublicPriceIndexUrl $urls,
        PublicIndexFormatter $formatter,
        PublicIndexStructuredData $structuredData,
        PublicIndexFamilyRegistry $families,
    ): View {
        $descriptor = $families->get($family);
        $page = $pages->execute($descriptor->code, $slug);
        $publicObservations = $observations->execute($page);
        $classifierContext = $classifierContexts->execute($page);
        $item = $page->classifierItem;
        $provider = $page->dataset?->provider_name ?: 'Росстат';
        $change = $formatter->percent($page->change_percent);
        $coefficient = $formatter->coefficient($page->coefficient);
        $latestDataYear = (int) $page->period_to->format('Y');
        $canonical = $urls->detail($slug, $descriptor->code);
        $providerCodeKind = $item?->metadata_json['provider_code_kind'] ?? null;
        $classifierLabel = $descriptor->supportsOkpd2((string) $item?->classifier_code)
            ? $formatter->classifierLabel(
                (string) $item?->classifier_code,
                is_string($providerCodeKind) ? $providerCodeKind : null,
            )
            : null;
        $indicatorType = $formatter->indicatorType(
            (string) $page->getAttribute('structured_indicator_name'),
            $provider,
        );
        $heading = $formatter->familyHeading(
            $descriptor,
            (string) $item?->item_code,
            (string) $item?->name,
            $classifierLabel,
        );
        $title = $formatter->familyDetailTitle(
            $descriptor,
            (string) $item?->item_code,
            (string) $item?->name,
            $indicatorType,
            $classifierLabel,
        );
        $description = $formatter->familyDescription(
            $descriptor,
            (string) $item?->name,
            $page->period_from,
            $page->period_to,
            $change,
            $coefficient,
            $provider,
        );
        $calculatorSupported = $page->series?->frequency === 'monthly'
            && $page->series?->comparison_basis === 'previous_month'
            && $page->series?->unit === 'percent';
        $publicChartData = $calculatorSupported
            ? $chartData->execute($page, $publicObservations)
            : null;

        $sourceNotes = collect($page->import?->metadata_json['source_notes'] ?? [])
            ->filter(fn (mixed $note): bool => is_array($note)
                && in_array($note['code'] ?? null, ['territorial_coverage_2026', 'january_1998_denomination'], true)
                && is_string($note['text'] ?? null)
                && trim($note['text']) !== '')
            ->values();

        return view('price-indices.public.detail', [
            'page' => $page,
            'observations' => $publicObservations,
            'latestObservation' => $publicObservations->last(),
            'relatedPages' => $classifierContext === null ? $relatedPages->execute($page) : collect(),
            'classifierContext' => $classifierContext,
            'family' => $descriptor,
            'sourceNotes' => $sourceNotes,
            'urls' => $urls,
            'formatter' => $formatter,
            'canonical' => $canonical,
            'calculationEndpoint' => $urls->calculation($slug, $descriptor->code),
            'calculatorSupported' => $calculatorSupported,
            'chartData' => $publicChartData,
            'calculatorUrl' => $urls->calculator(
                (string) $page->series?->public_id,
                $descriptor->code === PublicIndexFamilyRegistry::CONSUMER_PRICES
                    ? 'cpi-'.(string) $page->public_id
                    : (string) $item?->item_code,
            ),
            'title' => $title,
            'description' => $description,
            'latestDataYear' => $latestDataYear,
            'heading' => $heading,
            'indicatorType' => $indicatorType,
            'change' => $change,
            'coefficient' => $coefficient,
            'structuredData' => $structuredData->detail($page, $canonical, $heading, $description, $descriptor, $urls),
        ]);
    }
}
