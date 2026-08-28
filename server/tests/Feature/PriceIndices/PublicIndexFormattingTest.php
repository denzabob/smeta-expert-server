<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\PublicIndexFamilyRegistry;
use App\Domain\PriceIndices\Application\Support\PublicIndexFormatter;
use App\Domain\PriceIndices\Application\Support\PublicPriceIndexUrl;
use DateTimeImmutable;
use Tests\TestCase;

class PublicIndexFormattingTest extends TestCase
{
    public function test_decimal_period_title_and_attribution_are_deterministic_and_string_safe(): void
    {
        $formatter = app(PublicIndexFormatter::class);
        $urls = app(PublicPriceIndexUrl::class);
        config()->set('app.url', 'https://app.test');

        $this->assertSame('1,634146829442', $formatter->coefficient('1.634146829442'));
        $this->assertSame('+63,41 %', $formatter->percent('63.41'));
        $this->assertSame('−5,20 %', $formatter->percent('-5.20'));
        $this->assertSame('0,00 %', $formatter->percent('0.00'));
        $this->assertSame('105,82', $formatter->indexValue('105.8200000000'));
        $this->assertSame('+0,54 %', $formatter->monthlyChangeFromIndex('100.5400000000'));
        $this->assertSame('−0,13 %', $formatter->monthlyChangeFromIndex('99.8700000000'));
        $this->assertSame('0,00 %', $formatter->monthlyChangeFromIndex('100.0000000000'));
        $this->assertSame(
            'Январь 2021 — июнь 2026',
            $formatter->periodRange(new DateTimeImmutable('2021-01-01'), new DateTimeImmutable('2026-06-01')),
        );
        $this->assertLessThanOrEqual(95, mb_strlen($formatter->detailTitle(
            '31.02.10.140',
            str_repeat('Очень длинное название ', 10),
            'Индекс цен производителей Росстата',
            'ОКПД2',
        )));
        $this->assertSame('ОКПД2', $formatter->classifierLabel('okpd2_based', null));
        $this->assertNull($formatter->classifierLabel('okpd2_based', 'rosstat_local_ag'));
        $this->assertSame(
            '05.10.10.101.АГ — Уголь местной классификации',
            $formatter->heading('05.10.10.101.АГ', 'Уголь местной классификации', null),
        );
        $this->assertSame('31_02_10_140', $urls->refContent('31.02.10.140'));
        $this->assertSame('05_10_10_101_ag', $urls->refContent('05.10.10.101.АГ'));
        $this->assertSame(
            'https://indices.test/consumer-prices/food-products',
            $urls->detail('food-products', PublicIndexFamilyRegistry::CONSUMER_PRICES),
        );
        $this->assertSame('https://indices.test/consumer-prices/', $urls->consumerPrices());
    }
}
