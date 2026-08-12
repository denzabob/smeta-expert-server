<?php

namespace Tests\Feature\PriceIndices;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicSeoDynamicDataYearTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    public function test_catalog_title_uses_latest_indexable_snapshot_year_2026(): void
    {
        $this->fixtureForRange('2025-07', '2026-06');

        $this->get('https://indices.test/')
            ->assertOk()
            ->assertSee('<title>Индексы цен Росстата 2026 — индексы цен производителей | ПРИЗМА</title>', false)
            ->assertSee('Актуальные данные представлены по 2026 год включительно.');
    }

    public function test_catalog_year_rolls_over_to_2027_from_snapshot_data(): void
    {
        $this->fixtureForRange('2026-02', '2027-01');

        $this->get('https://indices.test/')
            ->assertOk()
            ->assertSee('<title>Индексы цен Росстата 2027 — индексы цен производителей | ПРИЗМА</title>', false)
            ->assertSee('на 2027 год');
    }

    public function test_system_clock_and_newer_non_indexable_snapshot_do_not_change_catalog_year(): void
    {
        CarbonImmutable::setTestNow('2027-09-15 12:00:00');
        try {
            $this->fixtureForRange('2026-01', '2026-12');
            $hidden = $this->fixtureForRange('2027-01', '2027-12', '31.02.10.141');
            $hidden['page']->update(['is_indexable' => false]);

            $response = $this->get('https://indices.test/');
            $response->assertOk()
                ->assertSee('<title>Индексы цен Росстата 2026 — индексы цен производителей | ПРИЗМА</title>', false)
                ->assertDontSee('Индексы цен Росстата 2027', false);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_detail_uses_its_own_2025_snapshot_year_when_catalog_latest_is_2026(): void
    {
        $detail = $this->fixtureForRange('2025-01', '2025-12');
        $this->fixtureForRange('2026-01', '2026-12', '31.02.10.141');

        $response = $this->get('https://indices.test/'.$detail['page']->slug);
        $html = $response->getContent();
        preg_match('/<title>(.*?)<\/title>/s', $html, $title);

        $response->assertOk()
            ->assertSee('2025 — Росстат | ПРИЗМА</title>', false)
            ->assertSee('по декабрь 2025')
            ->assertSee('temporalCoverage":"2025-01/2025-12', false);
        $this->assertStringContainsString('2025', $title[1]);
        $this->assertStringNotContainsString('2026', $title[1]);
    }

    /** @return array<string, mixed> */
    private function fixtureForRange(
        string $from,
        string $to,
        string $itemCode = '31.02.10.140',
    ): array {
        return $this->publicSeoFixture(
            $itemCode,
            $itemCode === '31.02.10.140' ? 'Наборы кухонной мебели' : 'Шкафы кухонные',
            $this->monthlySnapshotValues($from, $to),
        );
    }
}
