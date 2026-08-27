<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicSeoContentQualityTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    public function test_catalog_content_describes_both_public_families_with_dynamic_year_and_no_meta_keywords(): void
    {
        $this->publicSeoFixture(
            values: $this->monthlySnapshotValues('2025-07', '2026-06'),
        );

        $response = $this->get('https://indices.test/');
        $response->assertOk()
            ->assertSee('<h1>Индексы цен Росстата</h1>', false)
            ->assertSee('Индексы цен производителей')
            ->assertSee('Индексы потребительских цен')
            ->assertSee('Данные представлены по 2026 год включительно.')
            ->assertSee('https://indices.test/producer-prices/', false)
            ->assertSee('https://indices.test/consumer-prices/', false)
            ->assertDontSee('<meta name="keywords"', false);
    }

    public function test_detail_intro_uses_snapshot_metrics_and_natural_monthly_index_phrases(): void
    {
        $fixture = $this->publicSeoFixture(
            values: $this->monthlySnapshotValues('2025-07', '2026-06'),
        );

        $response = $this->get('https://indices.test/'.$fixture['page']->slug);
        $response->assertOk()
            ->assertSee('доступны индексы цен производителей с июля 2025 по июнь 2026')
            ->assertSee('Накопленный коэффициент изменения цен за этот период составляет 1,634146829442')
            ->assertSee('официальные месячные индексы цен товара к предыдущему месяцу')
            ->assertSee('+63,41 %')
            ->assertDontSee('<meta name="keywords"', false);

        $this->assertCpiPhrasesAreAbsent($response->getContent());
    }

    private function assertCpiPhrasesAreAbsent(string $html): void
    {
        $normalized = mb_strtolower(strip_tags($html), 'UTF-8');
        $this->assertStringNotContainsString('индекс потребительских цен', $normalized);
        $this->assertStringNotContainsString('росстат индекс потребительских цен', $normalized);
    }
}
