<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndicesCatalogTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_catalog_is_host_isolated_indexable_and_server_rendered(): void
    {
        $fixture = $this->publicSeoFixture();
        $fixture['page']->update(['is_indexable' => false]);
        $hiddenName = $fixture['item']->name;
        $visible = $this->publicSeoFixture('31.02.10.141', 'Шкафы кухонные');

        $response = $this->get('https://indices.test/');

        $response->assertOk()
            ->assertHeader('content-type', 'text/html; charset=utf-8')
            ->assertSee('<h1>Индексы цен Росстата</h1>', false)
            ->assertSee($visible['item']->item_code)
            ->assertSee($visible['item']->name)
            ->assertSee('https://indices.test/'.$visible['page']->slug, false)
            ->assertDontSee($hiddenName);

        $this->get('https://app.test/')
            ->assertOk()
            ->assertDontSee('Индексы цен производителей Росстата по товарам');
    }

    public function test_catalog_paginates_fifty_rows_with_bounded_queries(): void
    {
        $fixture = $this->publicSeoFixture();
        foreach (range(1, 50) as $ordinal) {
            $this->addPublicSeoPage($fixture, $ordinal);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->get('https://indices.test/?page=2');
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "Public catalog rendering: {$elapsedMs} ms, {$queries} queries\n");
        $response->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/?page=2">', false)
            ->assertSee('Тестовый товар 50');
        $this->assertLessThanOrEqual(5, $queries);
        $this->assertLessThan(1500, $elapsedMs);
    }
}
