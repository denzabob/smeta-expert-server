<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\Feature\PriceIndices\Support\ParsesPublicStructuredData;
use Tests\TestCase;

class PublicSeoTechnicalAuditTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;
    use ParsesPublicStructuredData;

    public function test_catalog_and_detail_raw_html_have_single_complete_indexable_metadata_set(): void
    {
        $fixture = $this->publicSeoFixture();
        foreach (['https://indices.test/', 'https://indices.test/'.$fixture['page']->slug] as $url) {
            $response = $this->get($url);
            $html = $response->getContent();

            $response->assertOk()
                ->assertSee('<html lang="ru">', false)
                ->assertSee('<meta charset="utf-8">', false)
                ->assertSee('<meta name="viewport" content="width=device-width, initial-scale=1">', false)
                ->assertSee('<meta name="robots" content="index,follow">', false)
                ->assertSee('<meta property="og:title"', false)
                ->assertSee('<meta property="og:description"', false)
                ->assertSee('<meta property="og:url"', false)
                ->assertDontSee('noindex', false);
            $this->assertSame(1, substr_count($html, '<title>'));
            $this->assertSame(1, substr_count($html, '<meta name="description"'));
            $this->assertSame(1, substr_count($html, '<link rel="canonical"'));
            $this->assertSame(1, preg_match_all('/<h1(?:\s[^>]*)?>/i', $html));
            $this->assertGreaterThanOrEqual(1, preg_match_all('/<a\s[^>]*href="https:\/\/[^\"]+"/i', $html));
            $this->structuredData($response);
        }
    }

    public function test_pagination_has_self_canonical_unique_title_and_description(): void
    {
        $fixture = $this->publicSeoFixture();
        foreach (range(1, 50) as $ordinal) {
            $this->addPublicSeoPage($fixture, $ordinal);
        }

        $pageOne = $this->get('https://indices.test/')->getContent();
        $pageTwoResponse = $this->get('https://indices.test/?page=2');
        $pageTwo = $pageTwoResponse->getContent();

        $pageTwoResponse->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/?page=2">', false)
            ->assertSee('— страница 2</title>', false)
            ->assertSee('Страница 2.', false);
        preg_match('/<title>(.*?)<\/title>/s', $pageOne, $titleOne);
        preg_match('/<title>(.*?)<\/title>/s', $pageTwo, $titleTwo);
        preg_match('/<meta name="description" content="(.*?)">/s', $pageOne, $descriptionOne);
        preg_match('/<meta name="description" content="(.*?)">/s', $pageTwo, $descriptionTwo);
        $this->assertNotSame($titleOne[1], $titleTwo[1]);
        $this->assertNotSame($descriptionOne[1], $descriptionTwo[1]);
    }

    public function test_robots_sitemap_status_and_non_indexable_contract_remain_unchanged(): void
    {
        $fixture = $this->publicSeoFixture();
        $fixture['page']->update(['is_indexable' => false]);

        $this->get('https://indices.test/'.$fixture['page']->slug)->assertNotFound();
        $this->get('https://indices.test/unknown')->assertNotFound();
        $this->get('https://indices.test/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nAllow: /\n\nSitemap: https://indices.test/sitemap.xml", false);
        $this->get('https://indices.test/sitemap.xml')
            ->assertOk()
            ->assertDontSee($fixture['page']->slug)
            ->assertDontSee('<loc>https://indices.test/?', false);
        $this->assertGuest();
    }
}
