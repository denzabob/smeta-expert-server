<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndicesSitemapTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    public function test_sitemap_and_robots_are_reserved_host_aware_and_indexable_only(): void
    {
        $visible = $this->publicSeoFixture();
        $hidden = $this->publicSeoFixture('31.02.10.141', 'Скрытый товар');
        $hidden['page']->update(['is_indexable' => false]);

        $sitemap = $this->get('https://indices.test/sitemap.xml');
        $sitemap->assertOk()
            ->assertHeader('content-type', 'application/xml; charset=UTF-8')
            ->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false)
            ->assertSee('<loc>https://indices.test/'.$visible['page']->slug.'</loc>', false)
            ->assertSee('<lastmod>', false)
            ->assertDontSee($hidden['page']->slug)
            ->assertDontSee('app.test')
            ->assertDontSee('verify.prismcore.ru');

        $this->get('https://indices.test/robots.txt')
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertSee("User-agent: *\nAllow: /\n\nSitemap: https://indices.test/sitemap.xml", false);

        $this->get('https://app.test/sitemap.xml')->assertNotFound();
    }

    public function test_sitemap_xml_escapes_configured_absolute_urls(): void
    {
        $fixture = $this->publicSeoFixture();
        config()->set('price_indices.public_url', 'https://indices.test/path&catalog');

        $this->get('https://indices.test/sitemap.xml')
            ->assertOk()
            ->assertSee('https://indices.test/path&amp;catalog/'.$fixture['page']->slug, false)
            ->assertDontSee('path&catalog', false);
    }
}
