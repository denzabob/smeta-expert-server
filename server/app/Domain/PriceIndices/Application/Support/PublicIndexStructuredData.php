<?php

namespace App\Domain\PriceIndices\Application\Support;

use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;

final class PublicIndexStructuredData
{
    /** @return array<string, mixed> */
    public function catalog(string $title, string $description, PublicPriceIndexUrl $urls): array
    {
        $root = $urls->catalog();
        $organization = $this->organization($root);

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebSite',
                    '@id' => $root.'#website',
                    'url' => $root,
                    'name' => 'ПРИЗМА Индексы',
                    'inLanguage' => 'ru-RU',
                    'publisher' => ['@id' => $root.'#organization'],
                ],
                $organization,
                [
                    '@type' => 'DataCatalog',
                    '@id' => $root.'#catalog',
                    'name' => 'Индексы цен производителей по товарам',
                    'description' => $description,
                    'url' => $root,
                    'inLanguage' => 'ru-RU',
                    'publisher' => ['@id' => $root.'#organization'],
                    'provider' => [
                        '@type' => 'Organization',
                        'name' => 'Росстат',
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function detail(
        StatisticalPublicSeriesPage $page,
        string $canonical,
        string $heading,
        string $description,
        PublicPriceIndexUrl $urls,
    ): array {
        $root = $urls->catalog();
        $datasetId = $canonical.'#dataset';
        $breadcrumbId = $canonical.'#breadcrumb';
        $variableId = $canonical.'#variable';
        $providerName = $page->dataset?->provider_name ?: 'Росстат';
        $itemName = (string) $page->classifierItem?->name;
        $itemCode = (string) $page->classifierItem?->item_code;
        $indicatorName = (string) ($page->getAttribute('structured_indicator_name') ?: 'Индекс цен производителей');

        $dataset = [
            '@type' => 'Dataset',
            '@id' => $datasetId,
            'name' => $heading,
            'description' => $description,
            'url' => $canonical,
            'identifier' => $itemCode,
            'inLanguage' => 'ru-RU',
            'temporalCoverage' => $page->period_from->format('Y-m').'/'.$page->period_to->format('Y-m'),
            'provider' => [
                '@type' => 'Organization',
                'name' => $providerName,
            ],
            'publisher' => ['@id' => $root.'#organization'],
            'includedInDataCatalog' => ['@id' => $root.'#catalog'],
            'variableMeasured' => ['@id' => $variableId],
            'dateModified' => $page->generated_at->toAtomString(),
            'measurementTechnique' => 'Индекс цен производителей к предыдущему месяцу',
        ];

        $territoryName = $page->getAttribute('structured_territory_name');
        if (is_string($territoryName) && $territoryName !== '') {
            $dataset['spatialCoverage'] = [
                '@type' => 'Place',
                'name' => $territoryName,
                'identifier' => $page->getAttribute('structured_territory_code'),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'WebPage',
                    '@id' => $canonical.'#webpage',
                    'url' => $canonical,
                    'name' => $heading,
                    'description' => $description,
                    'isPartOf' => ['@id' => $root.'#website'],
                    'breadcrumb' => ['@id' => $breadcrumbId],
                    'mainEntity' => ['@id' => $datasetId],
                    'inLanguage' => 'ru-RU',
                    'dateModified' => $page->generated_at->toAtomString(),
                ],
                [
                    '@type' => 'BreadcrumbList',
                    '@id' => $breadcrumbId,
                    'itemListElement' => [
                        [
                            '@type' => 'ListItem',
                            'position' => 1,
                            'name' => 'Индексы',
                            'item' => $root,
                        ],
                        [
                            '@type' => 'ListItem',
                            'position' => 2,
                            'name' => $itemName,
                            'item' => $canonical,
                        ],
                    ],
                ],
                $dataset,
                [
                    '@type' => 'StatisticalVariable',
                    '@id' => $variableId,
                    'name' => $indicatorName,
                    'description' => 'Индекс цен производителей по отношению к предыдущему месяцу',
                    'unitText' => 'процент',
                    'measurementTechnique' => 'Индекс цен производителей к предыдущему месяцу',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function organization(string $root): array
    {
        $organization = [
            '@type' => 'Organization',
            '@id' => $root.'#organization',
            'name' => 'ПРИЗМА',
        ];
        $brandUrl = (string) config('price_indices.brand_url');
        if (filter_var($brandUrl, FILTER_VALIDATE_URL) !== false) {
            $organization['url'] = rtrim($brandUrl, '/');
        }

        return $organization;
    }
}
