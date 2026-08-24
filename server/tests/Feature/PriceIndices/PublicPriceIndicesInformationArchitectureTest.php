<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\PublicSeriesIndexabilityStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\Feature\PriceIndices\Support\ParsesPublicStructuredData;
use Tests\TestCase;

class PublicPriceIndicesInformationArchitectureTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;
    use ParsesPublicStructuredData;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_family_landing_is_unique_ssr_page_built_from_published_snapshot_data(): void
    {
        $fixture = $this->publicSeoFixture();
        $fixture['sourceFile']->update(['source_url' => 'https://rosstat.gov.ru/statistics/price']);

        $response = $this->get('https://indices.test/producer-prices/');

        $response->assertOk()
            ->assertHeader('content-type', 'text/html; charset=utf-8')
            ->assertHeader('cache-control', 'max-age=300, public, s-maxage=600, stale-while-revalidate=60')
            ->assertHeaderMissing('set-cookie')
            ->assertSee('<title>Индексы цен производителей Росстата | ПРИЗМА</title>', false)
            ->assertSee('<h1>Индексы цен производителей Росстата</h1>', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/producer-prices/">', false)
            ->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertSee('<span class="metric__value">1</span>', false)
            ->assertSee('Январь 2025')
            ->assertSee('декабрь 2025')
            ->assertSee('01.07.2026')
            ->assertSee('https://indices.test/31-02-10-140', false)
            ->assertSee('https://rosstat.gov.ru/statistics/price', false);

        $schema = $this->structuredData($response);
        $this->assertSame('https://indices.test/producer-prices/', $this->graphEntity($schema, 'WebPage')['url']);
        $this->assertSame(2, count($this->graphEntity($schema, 'BreadcrumbList')['itemListElement']));
    }

    public function test_products_landing_is_an_overview_not_a_second_paginated_catalog(): void
    {
        $first = $this->publicSeoFixture();
        $related = $this->addPublicSeoPage($first, 1, '31.02.10.141', 'Шкафы кухонные');

        $response = $this->get('https://indices.test/producer-prices/products/');

        $response->assertOk()
            ->assertSee('<title>Индексы цен производителей по товарам и товарным группам | ПРИЗМА</title>', false)
            ->assertSee('<h1>Индексы цен производителей по товарам и товарным группам</h1>', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/producer-prices/products/">', false)
            ->assertSee('https://indices.test/'.$first['page']->slug, false)
            ->assertSee('https://indices.test/'.$related->slug, false)
            ->assertSee('Это обзор и навигационная страница')
            ->assertDontSee('<nav class="pagination"', false);
    }

    public function test_detail_keeps_legacy_canonical_and_links_only_real_related_pages(): void
    {
        $fixture = $this->publicSeoFixture();
        $related = $this->addPublicSeoPage($fixture, 1, '31.02.10.141', 'Шкафы кухонные');
        $hidden = $this->addPublicSeoPage($fixture, 2, '31.02.10.142', 'Скрытая группа');
        $hidden->update([
            'is_indexable' => false,
            'indexability_status' => PublicSeriesIndexabilityStatus::NotInActivePublication,
        ]);

        $response = $this->get('https://indices.test/31-02-10-140');

        $response->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/31-02-10-140">', false)
            ->assertSee('<h1>ОКПД2 31.02.10.140 — Наборы кухонной мебели</h1>', false)
            ->assertSee('<h2>Связанные индексы</h2>', false)
            ->assertSee('https://indices.test/'.$related->slug, false)
            ->assertDontSee($hidden->slug)
            ->assertDontSee('/okpd2/31-02-10-140', false);
    }

    public function test_local_rosstat_code_is_not_presented_as_okpd2(): void
    {
        $this->publicSeoFixture('05.10.10.101.АГ', 'Уголь местной классификации');

        $response = $this->get('https://indices.test/05-10-10-101-ag');

        $response->assertOk()
            ->assertSee('<h1>05.10.10.101.АГ — Уголь местной классификации</h1>', false)
            ->assertDontSee('<h1>ОКПД2', false)
            ->assertDontSee('на уголь местной классификации', false);
    }

    public function test_out_of_range_catalog_page_returns_404_while_last_real_page_remains_indexable(): void
    {
        $fixture = $this->publicSeoFixture();
        foreach (range(1, 50) as $ordinal) {
            $this->addPublicSeoPage($fixture, $ordinal);
        }

        $this->get('https://indices.test/?page=2')
            ->assertOk()
            ->assertSee('<meta name="robots" content="index,follow">', false);
        $this->get('https://indices.test/?page=3')->assertNotFound();
    }
}
