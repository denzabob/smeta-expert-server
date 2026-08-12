<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicIndexSeoMetadataTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_canonical_and_og_use_public_host_while_cta_uses_app_host(): void
    {
        $fixture = $this->publicSeoFixture();
        $response = $this->get('https://indices.test/31-02-10-140?utm_source=wrong');

        $response->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/31-02-10-140">', false)
            ->assertSee('<meta property="og:url" content="https://indices.test/31-02-10-140">', false)
            ->assertSee('series='.$fixture['series']->public_id, false)
            ->assertSee('ref=public_index', false)
            ->assertSee('ref_content=31_02_10_140', false)
            ->assertDontSee('https://app.test/31-02-10-140', false)
            ->assertDontSee('utm_', false);
    }

    public function test_rosstat_ag_code_keeps_source_identity_and_uses_ag_slug(): void
    {
        $fixture = $this->publicSeoFixture('05.10.10.101.АГ', 'Уголь местной классификации');

        $this->get('https://indices.test/05-10-10-101-ag')
            ->assertOk()
            ->assertSee('05.10.10.101.АГ')
            ->assertSee('https://indices.test/05-10-10-101-ag', false)
            ->assertSee('ref_content=05_10_10_101_ag', false);

        $this->assertSame('05.10.10.101.АГ', $fixture['item']->item_code);
    }
}
