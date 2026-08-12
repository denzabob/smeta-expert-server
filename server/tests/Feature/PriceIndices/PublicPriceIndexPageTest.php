<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Services\CalculateStatisticalIndexChain;
use App\Domain\PriceIndices\Application\Services\CalculateStatisticalIndexChange;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndexPageTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_detail_contains_complete_raw_ssr_content_from_exact_snapshot_scope(): void
    {
        $fixture = $this->publicSeoFixture();
        $otherItem = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'item_code' => '31.02.10.999',
            'name' => 'Чужой ряд',
            'normalized_name' => 'чужой ряд',
        ]);
        $otherSeries = $this->addSeriesForItem($fixture, $otherItem);
        StatisticalObservation::factory()->create([
            'import_id' => $fixture['import']->id,
            'series_id' => $otherSeries->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'period_start' => '2025-01-01',
            'value' => '999.0000000000',
        ]);

        $this->app->bind(CalculateStatisticalIndexChain::class, fn () => throw new RuntimeException('HTTP recalculation is forbidden.'));
        $this->app->bind(CalculateStatisticalIndexChange::class, fn () => throw new RuntimeException('HTTP recalculation is forbidden.'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->get('https://indices.test/31-02-10-140?ignored=1');
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        fwrite(STDERR, "Public detail rendering: {$elapsedMs} ms, {$queries} queries\n");
        $response->assertOk()
            ->assertSee('<h1>Индекс цен производителей на наборы кухонной мебели</h1>', false)
            ->assertSee('31.02.10.140')
            ->assertSee('1,634146829442')
            ->assertSee('+63,41 %')
            ->assertSee('Использовано месячных индексов')
            ->assertSee('producer_indices.xlsx')
            ->assertSee(str_repeat('a', 64))
            ->assertSee('https://indices.test/31-02-10-140', false)
            ->assertSee('https://app.test/app/indices/new?', false)
            ->assertDontSee('999,00')
            ->assertDontSee($fixture['sourceFile']->stored_path)
            ->assertDontSee('storage_disk');
        $this->assertLessThanOrEqual(10, $queries);
        $this->assertLessThan(1500, $elapsedMs);
    }

    public function test_unknown_and_non_indexable_slugs_are_not_public(): void
    {
        $fixture = $this->publicSeoFixture();
        $fixture['page']->update(['is_indexable' => false]);

        $this->get('https://indices.test/'.$fixture['page']->slug)->assertNotFound();
        $this->get('https://indices.test/unknown-slug')->assertNotFound();
        $this->get('https://indices.test/v/private-record')->assertNotFound();
        $this->get('https://indices.test/api/user')->assertNotFound();
    }
}
