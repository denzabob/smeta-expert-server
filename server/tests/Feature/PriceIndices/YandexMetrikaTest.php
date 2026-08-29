<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsConsumerPublicFixture;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class YandexMetrikaTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use BuildsConsumerPublicFixture;
    use DatabaseTransactions;

    public function test_counter_is_rendered_once_with_exact_configuration_and_noscript(): void
    {
        config()->set('price_indices.yandex_metrika_id', '111537697');
        $response = $this->get('https://indices.test/');
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('https://mc.yandex.ru/metrika/tag.js?id=111537697', false)
            ->assertSee("ym(111537697, 'init'", false)
            ->assertSee('ssr:true', false)
            ->assertSee('trackHash:true', false)
            ->assertSee('clickmap:true', false)
            ->assertSee('ecommerce:"dataLayer"', false)
            ->assertSee('accurateTrackBounce:true', false)
            ->assertSee('trackLinks:true', false)
            ->assertSee('https://mc.yandex.ru/watch/111537697', false);
        $this->assertSame(1, substr_count($html, '<!-- Yandex.Metrika counter -->'));
        $this->assertSame(1, substr_count($html, 'metrika/tag.js?id=111537697'));
        $this->assertSame(1, substr_count($html, 'mc.yandex.ru/watch/111537697'));
    }

    public function test_counter_is_absent_when_config_is_null(): void
    {
        config()->set('price_indices.yandex_metrika_id');

        $this->get('https://indices.test/')
            ->assertOk()
            ->assertDontSee('Yandex.Metrika counter', false)
            ->assertDontSee('mc.yandex.ru', false)
            ->assertDontSee('reachGoal', false);
    }

    public function test_calculator_goal_preserves_ordinary_cta_href_and_item_context(): void
    {
        config()->set('app.url', 'https://app.test');
        $fixture = $this->publicSeoFixture();

        $this->get('https://indices.test/'.$fixture['page']->slug)
            ->assertOk()
            ->assertSee('href="https://app.test/app/indices/new?', false)
            ->assertSee('data-metrika-goal="public_index_calculator_click"', false)
            ->assertSee('data-item-code="31.02.10.140"', false)
            ->assertSee('data-indices-event="indices_login_cta"', false)
            ->assertSee('data-index-family="producer_prices"', false)
            ->assertSee('data-index-series="'.$fixture['series']->public_id.'"', false)
            ->assertSee("'reachGoal'", false);
    }

    public function test_public_analytics_uses_only_whitelisted_context_and_sanitized_page_url(): void
    {
        $fixture = $this->publicSeoFixture();
        $fixture['sourceFile']->update(['source_url' => 'https://rosstat.gov.ru/statistics/price']);
        $consumerFixture = $this->consumerPublicFamilyFixture();
        $response = $this->get('https://indices.test/?q=');
        $resultResponse = $this->get('https://indices.test/');
        $detail = $this->get('https://indices.test/'.$fixture['page']->slug)->getContent();
        $consumerDetail = $this->get('https://indices.test/consumer-prices/'.$consumerFixture['pages']['all_items_and_services']->slug)->getContent();

        $response->assertOk()
            ->assertSee('data-indices-search-form', false)
            ->assertSee('data-indices-search-state="results"', false)
            ->assertSee('data-search-result-count="', false);
        $resultResponse->assertOk()
            ->assertSee('data-indices-event="indices_result_open"', false);
        $this->assertStringContainsString('data-indices-event="indices_source_open"', $detail);
        $this->assertStringContainsString('data-indices-event="full_history_open"', $consumerDetail);
        $this->assertStringContainsString('data-index-family="producer_prices"', $detail);
        $this->assertStringContainsString('data-index-series="'.$fixture['series']->public_id.'"', $detail);
        $this->assertStringContainsString('url: location.origin + location.pathname', $detail);
        $this->assertStringNotContainsString('url: location.href', $detail);
        $this->assertStringNotContainsString('item_code:', $detail);
    }
}
