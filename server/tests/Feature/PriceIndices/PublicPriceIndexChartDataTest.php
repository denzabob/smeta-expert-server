<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndexChartDataTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_chart_payload_keeps_all_ordered_points_from_the_exact_page_import_and_series(): void
    {
        $values = $this->monthlySnapshotValues('2020-01', '2025-06', '101.2500000000');
        $fixture = $this->publicSeoFixture(values: $values);
        $foreignItem = StatisticalClassifierItem::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'item_code' => '31.02.10.999',
            'name' => 'Чужой ряд',
            'normalized_name' => 'чужой ряд',
        ]);
        $foreignSeries = $this->addSeriesForItem($fixture, $foreignItem);
        StatisticalObservation::factory()->create([
            'import_id' => $fixture['import']->id,
            'series_id' => $foreignSeries->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'period_start' => '2020-01-01',
            'value' => '999.0000000000',
        ]);

        $newImport = StatisticalImport::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Published,
            'published_at' => '2026-08-01 10:00:00',
        ]);
        StatisticalObservation::factory()->create([
            'import_id' => $newImport->id,
            'series_id' => $fixture['series']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'period_start' => '2020-01-01',
            'value' => '777.0000000000',
        ]);
        $fixture['import']->update(['status' => StatisticalImportStatus::Superseded]);
        StatisticalDatasetActiveImport::query()->where('dataset_id', $fixture['dataset']->id)->update([
            'import_id' => $newImport->id,
            'published_at' => '2026-08-01 10:00:00',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->get('https://indices.test/'.$fixture['page']->slug)->assertOk();
        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $payload = $this->chartPayload($response->getContent());

        $this->assertCount(66, $payload['points']);
        $this->assertSame(range(1, 66), array_column($payload['points'], 'sequence'));
        $this->assertSame('2020-01', $payload['points'][0]['period']);
        $this->assertSame('2025-06', $payload['points'][65]['period']);
        $this->assertSame('163.4146829442', $payload['points'][1]['value']);
        $this->assertNotContains('777.0000000000', array_column($payload['points'], 'value'));
        $this->assertNotContains('999.0000000000', array_column($payload['points'], 'value'));
        $this->assertSame('2020-01', $payload['limits']['first_available_period']);
        $this->assertSame('2025-06', $payload['limits']['last_available_period']);
        $this->assertSame(120, $payload['limits']['calculator_max_range_months']);
        $this->assertSame('producer_prices', $payload['series']['family']);

        $encodedStarted = hrtime(true);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $encodingMicroseconds = (int) ((hrtime(true) - $encodedStarted) / 1_000);
        fwrite(STDERR, sprintf(
            "Public chart payload: %d bytes, %d µs; detail: %d ms, %d queries\n",
            strlen($encoded),
            $encodingMicroseconds,
            $elapsedMs,
            $queries,
        ));
        $this->assertLessThanOrEqual(12, $queries);
        $this->assertLessThan(3000, $elapsedMs);
        $this->assertLessThan(20_000, strlen($encoded));
    }

    public function test_missing_value_is_preserved_and_no_private_or_classifier_state_is_exposed(): void
    {
        $fixture = $this->publicSeoFixture();
        DB::table('statistical_observations')
            ->where('import_id', $fixture['import']->id)
            ->where('series_id', $fixture['series']->id)
            ->where('period_start', '2025-06-01')
            ->update(['value' => null, 'missing_reason' => 'ellipsis']);

        $html = $this->get('https://indices.test/'.$fixture['page']->slug)->assertOk()->getContent();
        $payload = $this->chartPayload($html);
        $missing = collect($payload['points'])->firstWhere('period', '2025-06');

        $this->assertNull($missing['value']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'dataset_id', 'import_id', 'series_id', 'source_row', 'source_column', 'stored_path',
            'parser', 'mapping', 'active_version', 'fingerprint',
        ] as $privateField) {
            $this->assertStringNotContainsString($privateField, $encoded);
        }
        $this->assertStringContainsString('id="public-price-index-chart-data"', $html);
        $this->assertStringContainsString('/price-indices-public-chart.js', $html);
        $this->assertStringNotContainsString('/okpd2/', $html);
    }

    public function test_chart_json_is_script_context_safe_for_hostile_text(): void
    {
        $hostile = '</script><script>window.compromised=true</script> & " quoted \' value';
        $fixture = $this->publicSeoFixture(itemName: $hostile);
        $html = $this->get('https://indices.test/'.$fixture['page']->slug)->assertOk()->getContent();
        $payload = $this->chartPayload($html);

        $this->assertSame($hostile, $payload['series']['title']);
        $this->assertStringNotContainsString($hostile, $this->chartJson($html));
        $this->assertStringNotContainsString('</script><script>window.compromised', $html);
        $this->assertStringContainsString('\\u003C\/script\\u003E', $this->chartJson($html));
    }

    /** @return array<string, mixed> */
    private function chartPayload(string $html): array
    {
        return json_decode($this->chartJson($html), true, flags: JSON_THROW_ON_ERROR);
    }

    private function chartJson(string $html): string
    {
        $matched = preg_match(
            '/<script id="public-price-index-chart-data" type="application\/json">(.*?)<\/script>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched, 'Chart JSON script element was not found.');

        return $matches[1];
    }
}
