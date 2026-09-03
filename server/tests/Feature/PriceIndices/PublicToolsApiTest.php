<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifier;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierActiveVersion;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItemMapping;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierNode;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierVersion;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingReviewStatus;
use App\Domain\PriceIndices\Domain\Enums\ClassifierItemMappingType;
use App\Domain\PriceIndices\Domain\Enums\ClassifierSemanticLevel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsConsumerPublicFixture;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicToolsApiTest extends TestCase
{
    use BuildsConsumerPublicFixture;
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_series_search_returns_only_public_ppi_and_cpi_contract(): void
    {
        $ppi = $this->publicSeoFixture();
        $cpi = $this->consumerPublicFamilyFixture();
        $ppi['page']->update(['is_indexable' => false]);

        $this->getJson('https://indices.test/api/public/v1/index-series/search?q=кухонная%20мебель&limit=30')
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $this->getJson('https://indices.test/api/public/v1/index-series/search?q=непродовольственные')
            ->assertOk()
            ->assertJsonPath('items.0.family', 'consumer_prices')
            ->assertJsonPath('items.0.title', 'Непродовольственные товары')
            ->assertJsonPath('items.0.min_period', '1991-01')
            ->assertJsonPath('items.0.max_period', '2026-07')
            ->assertJsonPath('items.0.detail_url', 'https://indices.test/consumer-prices/non-food-products');

        $this->getJson('https://indices.test/api/public/v1/index-series/search?q=кухонная%20мебель&family=producer_prices')
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $this->assertNotNull($cpi['pages']['non_food_products'] ?? null);
    }

    public function test_calculation_api_reuses_public_calculator_and_normalizes_amount(): void
    {
        $fixture = $this->publicSeoFixture();
        $url = 'https://indices.test/api/public/v1/index-series/calculate';
        $payload = [
            'family' => 'producer_prices',
            'slug' => $fixture['page']->slug,
            'start_period' => '2025-01',
            'end_period' => '2025-12',
            'amount' => '100 000,50',
        ];

        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('series.slug', $fixture['page']->slug)
            ->assertJsonPath('series.family', 'producer_prices')
            ->assertJsonPath('series.code', '31.02.10.140')
            ->assertJsonPath('period.months', 11)
            ->assertJsonPath('result.factor', '1.634146829442')
            ->assertJsonPath('result.change_percent', '63.41')
            ->assertJsonPath('result.amount', '100000.50')
            ->assertJsonPath('result.result_amount', '163415.50')
            ->assertJsonPath('result.delta_amount', '63415.00')
            ->assertJsonPath('source.publisher', 'Росстат');

        $this->postJson($url, [
            ...$payload,
            'amount' => null,
        ])->assertOk()
            ->assertJsonPath('result.amount', null)
            ->assertJsonPath('result.result_amount', null)
            ->assertJsonPath('result.delta_amount', null);

        $cpi = $this->consumerPublicFamilyFixture();
        $this->postJson($url, [
            'family' => 'consumer_prices',
            'slug' => $cpi['pages']['non_food_products']->slug,
            'start_period' => '2026-05',
            'end_period' => '2026-07',
            'amount' => null,
        ])->assertOk()
            ->assertJsonPath('series.family', 'consumer_prices')
            ->assertJsonPath('series.code', null)
            ->assertJsonPath('period.months', 2)
            ->assertJsonPath('result.amount', null);
    }

    public function test_calculation_api_has_stable_validation_and_domain_errors(): void
    {
        $fixture = $this->publicSeoFixture();
        $url = 'https://indices.test/api/public/v1/index-series/calculate';
        $base = [
            'family' => 'producer_prices',
            'slug' => $fixture['page']->slug,
            'start_period' => '2025-01',
            'end_period' => '2025-12',
        ];

        $this->postJson($url, [...$base, 'start_period' => '2025-12', 'end_period' => '2025-01'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->postJson($url, [...$base, 'start_period' => '2010-01', 'end_period' => '2021-01'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'PERIOD_TOO_LONG');

        $this->postJson($url, [...$base, 'slug' => 'unknown-series'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'SERIES_NOT_FOUND');

        $fixture['page']->update(['is_indexable' => false]);
        $this->postJson($url, $base)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'SERIES_NOT_FOUND');
    }

    public function test_okpd2_search_uses_active_snapshot_and_confirmed_index_mapping(): void
    {
        $fixture = $this->publicSeoFixture();
        $version = $this->activeOkpd2Version();
        $root = $this->node($version, '31', 'Мебель', 1);
        $node = $this->node($version, '31.02.10.140', 'Наборы кухонной мебели', 5, $root->id);
        $this->mapping($fixture['item']->id, $version->id, $node->id);

        $this->getJson('https://indices.test/api/public/v1/okpd2/search?q=31.02.10.140')
            ->assertOk()
            ->assertJsonPath('classifier.name', 'ОКПД2')
            ->assertJsonPath('items.0.code', '31.02.10.140')
            ->assertJsonPath('items.0.title', 'Наборы кухонной мебели')
            ->assertJsonPath('items.0.path.0.code', '31')
            ->assertJsonPath('items.0.price_index.available', true)
            ->assertJsonPath('items.0.price_index.url', 'https://indices.test/'.$fixture['page']->slug);

        $this->getJson('https://indices.test/api/public/v1/okpd2/search?q=31.02')
            ->assertOk()
            ->assertJsonPath('items.0.code', '31.02.10.140');

        $fixture['page']->update(['is_indexable' => false]);
        $this->getJson('https://indices.test/api/public/v1/okpd2/search?q=31.02.10.140')
            ->assertOk()
            ->assertJsonPath('items.0.price_index.available', false);
    }

    public function test_okpd2_search_fails_closed_without_active_snapshot(): void
    {
        $this->getJson('https://indices.test/api/public/v1/okpd2/search?q=мебель')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'CLASSIFIER_UNAVAILABLE');
    }

    private function activeOkpd2Version(): StatisticalClassifierVersion
    {
        $classifier = StatisticalClassifier::factory()->create(['code' => 'okpd2']);
        $version = StatisticalClassifierVersion::factory()->for($classifier, 'classifier')->create();
        StatisticalClassifierActiveVersion::query()->create([
            'classifier_id' => $classifier->id,
            'classifier_version_id' => $version->id,
            'activated_at' => now(),
            'activation_reason' => 'public-tools-test',
        ]);

        return $version;
    }

    private function node(
        StatisticalClassifierVersion $version,
        string $code,
        string $name,
        int $depth,
        ?int $parentId = null,
    ): StatisticalClassifierNode {
        return StatisticalClassifierNode::factory()->create([
            'classifier_version_id' => $version->id,
            'code' => $code,
            'name' => $name,
            'normalized_name' => app(StatisticalNameNormalizer::class)->normalize($name),
            'semantic_level' => $depth === 1 ? ClassifierSemanticLevel::Section : ClassifierSemanticLevel::Category,
            'formal_depth' => $depth,
            'parent_node_id' => $parentId,
        ]);
    }

    private function mapping(int $itemId, int $versionId, int $nodeId): void
    {
        StatisticalClassifierItemMapping::query()->create([
            'statistical_classifier_item_id' => $itemId,
            'classifier_version_id' => $versionId,
            'classifier_node_id' => $nodeId,
            'mapping_type' => ClassifierItemMappingType::Exact,
            'review_status' => ClassifierItemMappingReviewStatus::Confirmed,
            'method' => 'test:public-tools',
            'confirmed_at' => now(),
        ]);
    }
}
