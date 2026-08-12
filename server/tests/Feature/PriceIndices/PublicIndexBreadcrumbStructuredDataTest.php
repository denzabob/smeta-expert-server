<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\Feature\PriceIndices\Support\ParsesPublicStructuredData;
use Tests\TestCase;

class PublicIndexBreadcrumbStructuredDataTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;
    use ParsesPublicStructuredData;

    public function test_breadcrumb_schema_matches_visible_breadcrumbs_and_canonical(): void
    {
        $fixture = $this->publicSeoFixture();
        $response = $this->get('https://indices.test/'.$fixture['page']->slug);
        $breadcrumb = $this->graphEntity($this->structuredData($response), 'BreadcrumbList');
        $items = $breadcrumb['itemListElement'];

        $this->assertSame('https://indices.test/31-02-10-140#breadcrumb', $breadcrumb['@id']);
        $this->assertSame(1, $items[0]['position']);
        $this->assertSame('Индексы', $items[0]['name']);
        $this->assertSame('https://indices.test/', $items[0]['item']);
        $this->assertSame(2, $items[1]['position']);
        $this->assertSame($fixture['item']->name, $items[1]['name']);
        $this->assertSame('https://indices.test/31-02-10-140', $items[1]['item']);
        $response->assertSee('<nav class="crumbs"><a href="https://indices.test/">Индексы</a> → '.$fixture['item']->name.'</nav>', false);
    }
}
