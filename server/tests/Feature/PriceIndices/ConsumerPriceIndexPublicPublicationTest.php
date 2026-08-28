<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Services\RefreshPublicStatisticalSeriesPages;
use App\Domain\PriceIndices\Application\Support\DecimalMath;
use App\Domain\PriceIndices\Domain\PublicPages\StatisticalPublicSeriesPage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\PriceIndices\Support\BuildsConsumerPublicFixture;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class ConsumerPriceIndexPublicPublicationTest extends TestCase
{
    use BuildsConsumerPublicFixture;
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_cpi_is_a_family_safe_publication_with_full_history_search_and_calculation(): void
    {
        $ppi = $this->publicSeoFixture();
        $ppiSnapshot = $this->publicPagePersistenceSnapshot($ppi['page']);
        $fixture = $this->consumerPublicFamilyFixture();
        $families = app(PublicIndexFamilyRegistry::class);
        $consumer = $families->forDataset($fixture['dataset']);

        $this->assertSame(PublicIndexFamilyRegistry::CONSUMER_PRICES, $consumer->code);
        $this->assertFalse($consumer->supportsOkpd2('okpd2_based'));
        $this->assertSame([
            'all_items_and_services' => 'all-items-and-services',
            'food_products' => 'food-products',
            'non_food_products' => 'non-food-products',
            'services' => 'services',
        ], $fixture['pages']->mapWithKeys(fn (StatisticalPublicSeriesPage $page): array => [
            $page->classifierItem->item_code => $page->slug,
        ])->all());
        $this->assertCount(4, $fixture['pages']);
        foreach ($fixture['pages'] as $page) {
            $this->assertTrue($page->is_indexable);
            $this->assertSame($fixture['import']->id, $page->import_id);
            $this->assertSame(427, $page->observations_count);
            $this->assertSame('1991-01-01', $page->period_from->format('Y-m-d'));
            $this->assertSame('2026-07-01', $page->period_to->format('Y-m-d'));
        }
        $repeat = app(RefreshPublicStatisticalSeriesPages::class)->execute($fixture['dataset']->code);
        $this->assertSame(4, $repeat->unchanged);
        $this->assertSame(0, $repeat->created);
        $this->assertSame($ppiSnapshot, $this->publicPagePersistenceSnapshot($ppi['page']->fresh()));

        $landingMeasurement = $this->measureRequest(fn (): TestResponse => $this->get('https://indices.test/consumer-prices/'));
        $landing = $landingMeasurement['response'];
        $landing->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/consumer-prices/">', false)
            ->assertSee('Индекс потребительских цен (ИПЦ) Росстата')
            ->assertSee('100 % означает')
            ->assertSee('с 1991 года')
            ->assertSee('https://indices.test/consumer-prices/all-items-and-services', false)
            ->assertSee('https://indices.test/consumer-prices/food-products', false)
            ->assertSee('https://indices.test/consumer-prices/non-food-products', false)
            ->assertSee('https://indices.test/consumer-prices/services', false)
            ->assertSee('Индекс к предыдущему месяцу')
            ->assertSee('Изменение за месяц')
            ->assertSee('−1,00 %')
            ->assertSee('Открыть ряд');
        $this->assertSame(4, substr_count($landing->getContent(), 'class="panel card cpi-card"'));
        foreach (['all-items-and-services', 'food-products', 'non-food-products', 'services'] as $slug) {
            $this->assertMatchesRegularExpression(
                sprintf('/<a class="panel card cpi-card" href="https:\/\/indices\.test\/consumer-prices\/%s">/', preg_quote($slug, '/')),
                $landing->getContent(),
            );
        }
        $this->assertLessThanOrEqual(8, $landingMeasurement['queries']);
        $this->assertLessThan(3000, $landingMeasurement['elapsed_ms']);

        $root = $this->get('https://indices.test/')->assertOk();
        $root->assertSee('Индексы цен производителей')->assertSee('Индексы потребительских цен');
        $this->get('https://indices.test/producer-prices/')->assertOk()
            ->assertSee('<span class="metric__value">1</span>', false)
            ->assertDontSee('Товары и услуги');

        $detailMeasurement = $this->measureRequest(fn (): TestResponse => $this->get('https://indices.test/consumer-prices/food-products'));
        $detail = $detailMeasurement['response']->assertOk();
        $detail->assertSee('<h1>Индекс потребительских цен на продовольственные товары</h1>', false)
            ->assertSee('<link rel="canonical" href="https://indices.test/consumer-prices/food-products">', false)
            ->assertSee('Российская Федерация')
            ->assertSee('Индекс за месяц')
            ->assertSee('Накопленное изменение')
            ->assertSee('Последнее официальное значение')
            ->assertSee('Индекс к предыдущему месяцу')
            ->assertSee('99,00 %')
            ->assertSee('Изменение цен за месяц')
            ->assertSee('−1,00 %')
            ->assertSee('data-chart-range="1y"', false)
            ->assertSee('data-chart-range="3y"', false)
            ->assertSee('data-chart-range="5y" aria-pressed="true"', false)
            ->assertSee('data-chart-range="10y"', false)
            ->assertSee('data-chart-range="all"', false)
            ->assertSee('Последние 24 месяца')
            ->assertSee('Показать всю историю с января 1991 года — 427 месяцев')
            ->assertSee('Январь 1991')
            ->assertSee('Июль 2026')
            ->assertSee('Другие индексы потребительских цен')
            ->assertSee('Без учета статистической информации')
            ->assertSee('январе 1998 г. была проведена деноминация')
            ->assertSee('action="https://indices.test/consumer-prices/food-products/calculate"', false)
            ->assertDontSee('Официальная классификация')
            ->assertDontSee('ОКПД2')
            ->assertDontSee('food_products');
        $this->assertSame(24, $this->tableRowCount($detail->getContent(), '/<tbody data-recent-observations>(.*?)<\/tbody>/s'));
        $this->assertSame(427, $this->tableRowCount($detail->getContent(), '/<table data-full-history>(.*?)<\/table>/s'));
        $payload = $this->chartPayload($detail->getContent());
        $this->assertCount(427, $payload['points']);
        $this->assertNull($payload['series']['code']);
        $this->assertSame('1991-01', $payload['limits']['first_available_period']);
        $this->assertSame('2026-07', $payload['limits']['last_available_period']);
        $this->assertSame(120, $payload['limits']['calculator_max_range_months']);
        $this->assertSame(PublicIndexFamilyRegistry::CONSUMER_PRICES, $payload['series']['family']);
        $chartPayloadBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        $this->assertLessThan(150_000, $chartPayloadBytes);
        $this->assertLessThanOrEqual(11, $detailMeasurement['queries']);
        $this->assertLessThan(3000, $detailMeasurement['elapsed_ms']);

        foreach (['all-items-and-services', 'non-food-products', 'services'] as $slug) {
            $this->get('https://indices.test/consumer-prices/'.$slug)->assertOk();
            $this->get('https://indices.test/'.$slug)->assertNotFound();
        }
        $this->get('https://indices.test/'.$ppi['page']->slug)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/31-02-10-140">', false)
            ->assertSee('Помесячные индексы')
            ->assertDontSee('data-chart-range=', false)
            ->assertDontSee('data-full-history', false);

        $this->get('https://indices.test/?q=ипц')->assertOk()
            ->assertSeeInOrder(['Товары и услуги', 'Продовольственные товары', 'Непродовольственные товары', 'Услуги'])
            ->assertSee('https://indices.test/consumer-prices/all-items-and-services', false)
            ->assertSee('Индекс потребительских цен');
        $this->get('https://indices.test/?q=инфляция')->assertOk()
            ->assertSee('https://indices.test/consumer-prices/all-items-and-services', false);
        $this->get('https://indices.test/?q=продовольственные%20товары')->assertOk()
            ->assertSeeInOrder(['Продовольственные товары', 'Непродовольственные товары']);

        $searchMeasurement = $this->measureRequest(fn (): TestResponse => $this->get(
            'https://indices.test/?q=%D0%B8%D0%BF%D1%86',
        ));
        $searchMeasurement['response']->assertOk()
            ->assertSee('https://indices.test/consumer-prices/all-items-and-services', false);
        $this->assertLessThanOrEqual(5, $searchMeasurement['queries']);
        $this->assertLessThan(3000, $searchMeasurement['elapsed_ms']);

        $calculationMeasurement = $this->measureRequest(fn (): TestResponse => $this->postJson(
            'https://indices.test/consumer-prices/food-products/calculate',
            [
                'start_period' => '2026-04',
                'end_period' => '2026-07',
                'amount' => '1000.00',
            ],
        ));
        $calculation = $calculationMeasurement['response'];
        $decimal = app(DecimalMath::class);
        $directCoefficient = '1';
        foreach (['101.0000000000', '102.0000000000', '99.0000000000'] as $factor) {
            $directCoefficient = $decimal->multiply($directCoefficient, $decimal->divide($factor, '100'));
        }
        $directCoefficient = $decimal->roundHalfUp($directCoefficient, DecimalMath::COEFFICIENT_SCALE);
        $calculation->assertOk()
            ->assertJsonPath('data.page.family.code', PublicIndexFamilyRegistry::CONSUMER_PRICES)
            ->assertJsonPath('data.page.classifier.code', null)
            ->assertJsonPath('data.coefficient', $directCoefficient)
            ->assertJsonPath('data.change_percent', '1.99')
            ->assertJsonPath('data.amount.adjusted', '1019.90')
            ->assertJsonPath('data.provenance.publication.reference', $fixture['import']->public_id);
        $this->assertLessThanOrEqual(12, $calculationMeasurement['queries']);
        $this->assertLessThan(3000, $calculationMeasurement['elapsed_ms']);
        $this->postJson('https://indices.test/consumer-prices/food-products/calculate', [
            'start_period' => '1991-01',
            'end_period' => '2001-02',
        ])->assertUnprocessable()->assertJsonPath('code', 'period_too_long');

        $sitemap = $this->get('https://indices.test/sitemap.xml')->assertOk()
            ->assertSee('<loc>https://indices.test/consumer-prices/</loc>', false)
            ->assertSee('<loc>https://indices.test/consumer-prices/food-products</loc>', false)
            ->assertSee('<loc>https://indices.test/31-02-10-140</loc>', false)
            ->assertDontSee('?q=', false);
        $this->assertSame(5, substr_count($sitemap->getContent(), 'https://indices.test/consumer-prices/'));

        fwrite(STDERR, sprintf(
            "CPI public performance: landing %d ms/%d queries; detail %d ms/%d queries; search %d ms/%d queries; calculator %d ms/%d queries; chart %d bytes\n",
            $landingMeasurement['elapsed_ms'],
            $landingMeasurement['queries'],
            $detailMeasurement['elapsed_ms'],
            $detailMeasurement['queries'],
            $searchMeasurement['elapsed_ms'],
            $searchMeasurement['queries'],
            $calculationMeasurement['elapsed_ms'],
            $calculationMeasurement['queries'],
            $chartPayloadBytes,
        ));
    }

    /**
     * @param  callable(): TestResponse  $request
     * @return array{response: TestResponse, elapsed_ms: int, queries: int}
     */
    private function measureRequest(callable $request): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $startedAt = hrtime(true);
        $response = $request();
        $elapsedMilliseconds = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [
            'response' => $response,
            'elapsed_ms' => $elapsedMilliseconds,
            'queries' => $queries,
        ];
    }

    /**
     * @return array{import_id: int, series_id: int, generated_at: string|null, updated_at: string|null}
     */
    private function publicPagePersistenceSnapshot(StatisticalPublicSeriesPage $page): array
    {
        return [
            'import_id' => $page->import_id,
            'series_id' => $page->series_id,
            'generated_at' => $page->generated_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function chartPayload(string $html): array
    {
        $matched = preg_match(
            '/<script id="public-price-index-chart-data" type="application\/json">(.*?)<\/script>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched);

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function tableRowCount(string $html, string $tablePattern): int
    {
        $matched = preg_match($tablePattern, $html, $matches);
        $this->assertSame(1, $matched, 'Expected table fragment was not found.');

        return preg_match_all('/<tr><td>/', $matches[1]);
    }
}
