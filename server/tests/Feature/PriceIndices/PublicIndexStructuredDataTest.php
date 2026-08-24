<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\Feature\PriceIndices\Support\ParsesPublicStructuredData;
use Tests\TestCase;

class PublicIndexStructuredDataTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;
    use ParsesPublicStructuredData;

    public function test_catalog_json_ld_contains_website_organization_and_data_catalog(): void
    {
        config()->set('price_indices.brand_url', 'https://prismcore.test');
        $response = $this->get('https://indices.test/');
        $schema = $this->structuredData($response);
        $website = $this->graphEntity($schema, 'WebSite');
        $organization = $this->graphEntity($schema, 'Organization');
        $catalog = $this->graphEntity($schema, 'DataCatalog');

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('https://indices.test/#website', $website['@id']);
        $this->assertSame('https://indices.test/', $website['url']);
        $this->assertSame('ПРИЗМА Индексы', $website['name']);
        $this->assertSame('ru-RU', $website['inLanguage']);
        $this->assertSame(['@id' => 'https://indices.test/#organization'], $website['publisher']);
        $this->assertSame('ПРИЗМА', $organization['name']);
        $this->assertSame('https://prismcore.test', $organization['url']);
        $this->assertSame('https://indices.test/#catalog', $catalog['@id']);
        $this->assertSame('Росстат', $catalog['provider']['name']);
        $this->assertStringNotContainsString('app.test', json_encode($schema, JSON_UNESCAPED_UNICODE));
    }

    public function test_json_ld_is_script_safe_for_untrusted_item_metadata(): void
    {
        $fixture = $this->publicSeoFixture('31.02.10.140', '</script><script>alert("seo")</script> Мебель');

        $response = $this->get('https://indices.test/'.$fixture['page']->slug);
        $schema = $this->structuredData($response);
        $this->assertSame(1, substr_count($response->getContent(), 'application/ld+json'));
        $this->assertStringNotContainsString('</script><script>alert', $response->getContent());
        $this->assertSame($fixture['item']->name, $this->graphEntity($schema, 'BreadcrumbList')['itemListElement'][3]['name']);
    }
}
