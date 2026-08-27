<?php

namespace App\Domain\PriceIndices\Application\Support;

use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use InvalidArgumentException;

final class PublicPriceIndexUrl
{
    public function __construct(private readonly PublicIndexFamilyRegistry $families) {}

    public function catalog(int $page = 1): string
    {
        $url = $this->publicBaseUrl().'/';

        return $page > 1 ? $url.'?page='.$page : $url;
    }

    public function detail(string $slug, string $familyCode = PublicIndexFamilyRegistry::PRODUCER_PRICES): string
    {
        $family = $this->families->get($familyCode);

        return $this->publicBaseUrl().$family->detailPathPrefix.'/'.rawurlencode($slug);
    }

    public function detailForPage(StatisticalPublicSeriesPage $page): string
    {
        return $this->detail((string) $page->slug, $this->families->forDataset($page->dataset)->code);
    }

    public function catalogSearch(string $query, int $page = 1): string
    {
        $parameters = ['q' => $query];
        if ($page > 1) {
            $parameters['page'] = $page;
        }

        return $this->catalog().'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function calculation(string $slug, string $familyCode = PublicIndexFamilyRegistry::PRODUCER_PRICES): string
    {
        return $this->detail($slug, $familyCode).'/calculate';
    }

    public function producerPrices(): string
    {
        return $this->publicBaseUrl().'/producer-prices/';
    }

    public function producerPriceProducts(): string
    {
        return $this->publicBaseUrl().'/producer-prices/products/';
    }

    public function consumerPrices(): string
    {
        return $this->publicBaseUrl().$this->families->get(PublicIndexFamilyRegistry::CONSUMER_PRICES)->landingPath;
    }

    public function sitemap(): string
    {
        return $this->publicBaseUrl().'/sitemap.xml';
    }

    public function calculator(string $seriesPublicId, string $itemCode): string
    {
        $query = http_build_query([
            'series' => $seriesPublicId,
            'ref' => 'public_index',
            'ref_content' => $this->refContent($itemCode),
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->appBaseUrl().'/app/indices/new?'.$query;
    }

    public function refContent(string $itemCode): string
    {
        $value = preg_replace('/[\x{00A0}\x{202F}\s]+/u', '', trim($itemCode)) ?? '';
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['.аг', '.'], ['_ag', '_'], $value);

        return preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
    }

    private function publicBaseUrl(): string
    {
        return $this->configuredBaseUrl('price_indices.public_url');
    }

    private function appBaseUrl(): string
    {
        return $this->configuredBaseUrl('app.url');
    }

    private function configuredBaseUrl(string $key): string
    {
        $url = rtrim((string) config($key), '/');
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException("Invalid configured URL: {$key}.");
        }

        return $url;
    }
}
