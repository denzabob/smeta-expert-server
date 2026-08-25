<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItemMapping;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingReviewStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingType;
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
            ->assertSee('ничего не найдено в опубликованных данных Росстата и ОКПД2')
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

    public function test_active_okpd2_search_supports_exact_code_prefix_name_prefix_and_contains_modes(): void
    {
        $version = $this->activeOkpd2Version();
        $exact = $this->canonicalNode($version, '31.02.10.140', 'Наборы кухонной мебели');
        $prefix = $this->canonicalNode($version, '31.02.10.141', 'Шкафы кухонные деревянные');
        $contains = $this->canonicalNode($version, '27.32.13.111', 'Кабели силовые специальные');

        $this->get('https://indices.test/?q=31.02.10.140')
            ->assertOk()
            ->assertSee('<span class="code">ОКПД2 '.$exact->code.'</span>', false)
            ->assertSee($exact->name)
            ->assertSee('Отдельный опубликованный ряд Росстата не найден')
            ->assertDontSee('/okpd2/', false);

        $this->get('https://indices.test/?q=31.02.10')
            ->assertOk()
            ->assertSeeInOrder([$exact->code, $prefix->code]);

        $this->get('https://indices.test/?q='.urlencode('  НАБОРЫ КУХОННОЙ  '))
            ->assertOk()
            ->assertSee($exact->name)
            ->assertDontSee($contains->name);

        $this->get('https://indices.test/?q='.urlencode('силовые спец'))
            ->assertOk()
            ->assertSee($contains->name);
    }

    public function test_empty_catalog_request_does_not_query_active_classifier_nodes(): void
    {
        $version = $this->activeOkpd2Version();
        $this->canonicalNode($version, '31.02.10.140', 'Наборы кухонной мебели');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('https://indices.test/')->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertFalse((bool) array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'statistical_classifier_nodes'),
        ));
    }

    public function test_confirmed_mapping_enriches_statistical_result_suppresses_duplicate_and_uses_actual_slug(): void
    {
        $fixture = $this->publicSeoFixture();
        $version = $this->activeOkpd2Version();
        $node = $this->canonicalNode($version, $fixture['item']->item_code, $fixture['item']->name);
        $this->mapping($fixture['item']->id, $version->id, $node->id, 'confirmed', 'exact');

        $response = $this->get('https://indices.test/?q=31.02.10.140');
        $html = $response->getContent();

        $response->assertOk()
            ->assertSee('Есть опубликованные данные Росстата')
            ->assertSee('Открыть данные')
            ->assertSee('href="https://indices.test/'.$fixture['page']->slug.'"', false)
            ->assertDontSee('/okpd2/', false);
        $this->assertSame(1, substr_count($html, '<h2>'.$fixture['item']->name.'</h2>'));
    }

    public function test_code_equality_ambiguous_mapping_and_non_indexable_page_never_create_false_data_marker(): void
    {
        $unmapped = $this->publicSeoFixture('31.02.10.140', 'Наборы кухонной мебели');
        $ambiguous = $this->publicSeoFixture('31.02.10.141', 'Локальные шкафы');
        $hidden = $this->publicSeoFixture('31.02.10.142', 'Скрытые столы');
        $hidden['page']->update(['is_indexable' => false]);
        $version = $this->activeOkpd2Version();
        $unmappedNode = $this->canonicalNode($version, $unmapped['item']->item_code, $unmapped['item']->name);
        $ambiguousNode = $this->canonicalNode($version, $ambiguous['item']->item_code, 'Официальные шкафы');
        $hiddenNode = $this->canonicalNode($version, $hidden['item']->item_code, $hidden['item']->name);
        $this->mapping($ambiguous['item']->id, $version->id, $ambiguousNode->id, 'needs_review', 'ambiguous');
        $this->mapping($hidden['item']->id, $version->id, $hiddenNode->id, 'confirmed', 'exact');

        $this->get('https://indices.test/?q='.$unmappedNode->code)
            ->assertOk()
            ->assertDontSee('Есть опубликованные данные Росстата');
        $this->get('https://indices.test/?q='.$ambiguousNode->code)
            ->assertOk()
            ->assertDontSee('Есть опубликованные данные Росстата');
        $this->get('https://indices.test/?q='.$hiddenNode->code)
            ->assertOk()
            ->assertSee('Отдельный опубликованный ряд Росстата не найден')
            ->assertDontSee('Есть опубликованные данные Росстата')
            ->assertDontSee('href="https://indices.test/'.$hidden['page']->slug.'"', false);
    }

    public function test_combined_results_are_bounded_paginated_escaped_and_keep_query_seo_and_sitemap_contracts(): void
    {
        $version = $this->activeOkpd2Version();
        foreach (range(1, 25) as $ordinal) {
            $this->canonicalNode(
                $version,
                sprintf('31.99.10.%03d', $ordinal),
                'Кабель классификатора '.$ordinal,
            );
        }

        $response = $this->get('https://indices.test/?q='.urlencode('кабель').'&page=2');
        $response->assertOk()
            ->assertSee('<meta name="robots" content="noindex,follow">', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/">', false)
            ->assertSee('href="https://indices.test/?q=%D0%BA%D0%B0%D0%B1%D0%B5%D0%BB%D1%8C" rel="prev"', false)
            ->assertSee('Кабель классификатора 25');
        $this->assertSame(5, substr_count($response->getContent(), '<article class="panel card">'));
        $this->get('https://indices.test/?q='.urlencode('кабель').'&page=3')->assertNotFound();

        $this->get('https://indices.test/?q='.urlencode('%_!'))
            ->assertOk()
            ->assertSee('Ничего не найдено');
        $this->get('https://indices.test/sitemap.xml')
            ->assertOk()
            ->assertDontSee('31-99-10-001');
    }

    private function activeOkpd2Version(): StatisticalClassifierVersion
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);
        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $version->id,
            'activated_at' => now(),
            'activation_reason' => 'search-test',
        ]);

        return $version;
    }

    private function canonicalNode(
        StatisticalClassifierVersion $version,
        string $code,
        string $name,
    ): StatisticalClassifierNode {
        return StatisticalClassifierNode::factory()->create([
            'classifier_version_id' => $version->id,
            'code' => $code,
            'name' => $name,
            'normalized_name' => app(\App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer::class)->normalize($name),
        ]);
    }

    private function mapping(
        int $itemId,
        int $versionId,
        int $nodeId,
        string $reviewStatus,
        string $mappingType,
    ): StatisticalClassifierItemMapping {
        return StatisticalClassifierItemMapping::query()->create([
            'statistical_classifier_item_id' => $itemId,
            'classifier_version_id' => $versionId,
            'classifier_node_id' => $nodeId,
            'mapping_type' => ClassifierItemMappingType::from($mappingType),
            'review_status' => ClassifierItemMappingReviewStatus::from($reviewStatus),
            'method' => 'test:explicit',
            'confirmed_at' => $reviewStatus === 'confirmed' ? now() : null,
        ]);
    }
}
