<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndicesSearchTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_empty_catalog_remains_indexable_canonical_and_server_rendered(): void
    {
        $fixture = $this->publicSeoFixture();

        $this->get('https://indices.test/')
            ->assertOk()
            ->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/">', false)
            ->assertSee('<form class="panel search-form"', false)
            ->assertSee('<div class="search-controls">', false)
            ->assertSee('aria-describedby="public-index-search-help"', false)
            ->assertSee('<button class="button" type="submit">', false)
            ->assertSee('.search-controls .button { min-height:44px; margin-top:0; padding:9px 20px; border-radius:10px; white-space:nowrap; }', false)
            ->assertSee('.search-controls .button { width:100%; }', false)
            ->assertSee($fixture['item']->name)
            ->assertSee('application/ld+json', false);
    }

    public function test_exact_code_search_uses_public_snapshot_and_query_seo_contract(): void
    {
        $visible = $this->publicSeoFixture();
        $hidden = $this->publicSeoFixture('31.02.10.141', 'Скрытая кухонная позиция');
        $hidden['page']->update(['is_indexable' => false]);

        $response = $this->get('https://indices.test/?q=31.02.10.140');

        $response->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/">', false)
            ->assertDontSee('application/ld+json', false)
            ->assertSee($visible['item']->item_code)
            ->assertSee($visible['item']->name)
            ->assertSee('href="https://indices.test/'.$visible['page']->slug.'"', false)
            ->assertSee('Индекс цен производителей')
            ->assertDontSee($hidden['item']->name);
    }

    public function test_normalized_name_and_code_prefix_search_find_real_indexable_pages(): void
    {
        $kitchen = $this->publicSeoFixture();
        $cabinet = $this->publicSeoFixture('31.02.10.141', 'Шкафы кухонные деревянные');
        $cable = $this->publicSeoFixture('27.32.13.111', 'Кабели силовые');

        $this->get('https://indices.test/?q='.urlencode('кухонная мебель'))
            ->assertOk()
            ->assertSee($kitchen['item']->name)
            ->assertDontSee($cable['item']->name);

        $this->get('https://indices.test/?q=31.02.10')
            ->assertOk()
            ->assertSee($kitchen['item']->name)
            ->assertSee($cabinet['item']->name)
            ->assertDontSee($cable['item']->name);

        $this->get('https://indices.test/?q='.urlencode('кабель'))
            ->assertOk()
            ->assertSee($cable['item']->name);
    }

    public function test_empty_result_is_safe_escaped_noindex_response_not_404(): void
    {
        $this->publicSeoFixture();
        $malicious = '<script>alert(1)</script>';

        $response = $this->get('https://indices.test/?q='.urlencode($malicious));

        $response->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('ничего не найдено среди опубликованных индексов Росстата')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee($malicious, false)
            ->assertSee('Перейдите ко всем индексам');
    }

    public function test_search_results_are_bounded_paginated_and_render_with_bounded_queries(): void
    {
        $fixture = $this->publicSeoFixture();
        foreach (range(1, 25) as $ordinal) {
            $this->addPublicSeoPage($fixture, $ordinal, null, 'Кабель контрольный '.$ordinal);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->get('https://indices.test/?q='.urlencode('кабель').'&page=2');
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "Public search rendering: {$elapsedMs} ms, {$queries} queries\n");
        $response->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/">', false)
            ->assertSee('href="https://indices.test/?q=%D0%BA%D0%B0%D0%B1%D0%B5%D0%BB%D1%8C" rel="prev"', false)
            ->assertSee('Кабель контрольный');
        $this->assertLessThanOrEqual(5, $queries);
        $this->assertLessThan(1500, $elapsedMs);
    }
}
