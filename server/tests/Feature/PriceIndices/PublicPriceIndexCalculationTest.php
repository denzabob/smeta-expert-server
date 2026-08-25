<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\TestCase;

class PublicPriceIndexCalculationTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.test');
    }

    public function test_anonymous_detail_exposes_accessible_calculator_and_exact_snapshot_endpoint(): void
    {
        $fixture = $this->publicSeoFixture();

        $this->get('https://indices.test/'.$fixture['page']->slug)
            ->assertOk()
            ->assertSee('Рассчитать изменение за период')
            ->assertSee('<label for="calculation-start-period">', false)
            ->assertSee('<label for="calculation-end-period">', false)
            ->assertSee('<label for="calculation-amount">', false)
            ->assertSee('role="status" aria-live="polite"', false)
            ->assertSee('action="https://indices.test/'.$fixture['page']->slug.'/calculate"', false)
            ->assertSee('/price-indices-public-calculator.js', false)
            ->assertSee('Помесячные индексы')
            ->assertSee('Источник и версия');
        $this->assertGuest();
    }

    public function test_public_assets_keep_calculator_transport_separate_from_the_chart_bundle(): void
    {
        $calculator = file_get_contents(public_path('price-indices-public-calculator.js'));
        $chart = file_get_contents(public_path('price-indices-public-chart.js'));

        $this->assertIsString($calculator);
        $this->assertIsString($chart);
        $this->assertStringContainsString('data-public-index-calculator', $calculator);
        $this->assertStringContainsString('application/json', $calculator);
        $this->assertStringContainsString('price-indices:calculation-succeeded', $calculator);
        $this->assertStringNotContainsString('window.location', $calculator);
        $this->assertStringNotContainsString('history.', $calculator);
        $this->assertLessThan(20_000, strlen($calculator));

        $this->assertStringContainsString('data-public-index-chart', $chart);
        $this->assertStringContainsString('price-indices:calculation-succeeded', $chart);
        $this->assertStringNotContainsString('window.fetch', $chart);
        $this->assertStringNotContainsString('createApp', $chart);
        $this->assertStringNotContainsString('vuetify', mb_strtolower($chart));
        $this->assertGreaterThan(500_000, strlen($chart));
        $this->assertLessThan(650_000, strlen($chart));
        $this->assertLessThan(180_000, strlen(gzencode($chart, 9)));
    }

    public function test_anonymous_calculation_is_decimal_safe_with_optional_amount_and_public_chain(): void
    {
        $fixture = $this->publicSeoFixture();

        $withoutAmount = $this->calculate($fixture, '2025-01', '2025-12')
            ->assertOk()
            ->assertJsonPath('data.period.interval_semantics', '(start,end]')
            ->assertJsonPath('data.period.factors_count', 11)
            ->assertJsonPath('data.coefficient', '1.634146829442')
            ->assertJsonPath('data.change_percent', '63.41')
            ->assertJsonPath('data.amount', null)
            ->assertJsonPath('data.chain.0.period', '2025-02')
            ->assertJsonPath('data.chain.0.index', '163.4146829442')
            ->assertJsonPath('data.provenance.provider', 'Росстат')
            ->assertJsonPath('data.provenance.publication.reference', $fixture['import']->public_id)
            ->assertJsonPath('data.provenance.snapshot.reference', $fixture['page']->public_id);
        $this->assertStringContainsString('no-store', (string) $withoutAmount->headers->get('cache-control'));
        $this->assertFalse($withoutAmount->headers->has('set-cookie'));

        $this->calculate($fixture, '2025-01', '2025-12', '500000.00')
            ->assertOk()
            ->assertJsonPath('data.amount.original', '500000.00')
            ->assertJsonPath('data.amount.adjusted', '817073.41');

        $payload = json_encode($withoutAmount->json(), JSON_THROW_ON_ERROR);
        foreach (['dataset_id', 'import_id', 'series_id', 'source_row', 'source_column', 'source_cell', 'stored_path', 'storage_disk'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $payload);
        }
        $this->assertGuest();
    }

    public function test_calculation_remains_bound_to_page_import_when_active_publication_changes(): void
    {
        $fixture = $this->publicSeoFixture();
        $secondImport = StatisticalImport::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Published,
            'published_at' => '2026-08-01 10:00:00',
        ]);
        foreach ($this->monthlySnapshotValues('2025-01', '2025-12') as $period => $value) {
            StatisticalObservation::factory()->create([
                'import_id' => $secondImport->id,
                'series_id' => $fixture['series']->id,
                'source_file_id' => $fixture['sourceFile']->id,
                'period_start' => $period.'-01',
                'value' => $period === '2025-02' ? '200.0000000000' : $value,
            ]);
        }
        $fixture['import']->update(['status' => StatisticalImportStatus::Superseded]);
        StatisticalDatasetActiveImport::query()->where('dataset_id', $fixture['dataset']->id)->update([
            'import_id' => $secondImport->id,
            'published_at' => '2026-08-01 10:00:00',
        ]);

        $this->calculate($fixture, '2025-01', '2025-12')
            ->assertOk()
            ->assertJsonPath('data.coefficient', '1.634146829442')
            ->assertJsonPath('data.provenance.publication.reference', $fixture['import']->public_id)
            ->assertJsonMissing(['reference' => $secondImport->public_id]);
    }

    public function test_validation_boundaries_gaps_and_unsupported_pages_fail_closed(): void
    {
        $fixture = $this->publicSeoFixture();

        foreach (['0', '-1', '1e10', '1,000.00', '100.001', '1000000000000000'] as $invalid) {
            $this->calculate($fixture, '2025-01', '2025-12', $invalid)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'invalid_amount');
        }
        $this->calculate($fixture, '2025-12', '2025-01')
            ->assertUnprocessable()->assertJsonPath('code', 'invalid_period_range');
        $this->calculate($fixture, '2024-12', '2025-12')
            ->assertUnprocessable()->assertJsonPath('code', 'period_before_available_range');
        $this->calculate($fixture, '2025-01', '2026-01')
            ->assertUnprocessable()->assertJsonPath('code', 'period_after_available_range');
        $this->calculate($fixture, '2010-01', '2021-01')
            ->assertUnprocessable()->assertJsonPath('code', 'period_too_long');

        DB::table('statistical_observations')
            ->where('import_id', $fixture['import']->id)
            ->where('series_id', $fixture['series']->id)
            ->where('period_start', '2025-06-01')
            ->delete();
        $this->calculate($fixture, '2025-01', '2025-12')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'incomplete_observation_chain')
            ->assertJsonPath('details.missing_periods.0', '2025-06');

        $fixture['series']->update(['comparison_basis' => 'year_over_year']);
        $this->calculate($fixture, '2025-01', '2025-02')
            ->assertUnprocessable()->assertJsonPath('code', 'unsupported_series_calculation');
        $fixture['page']->update(['is_indexable' => false]);
        $this->calculate($fixture, '2025-01', '2025-02')
            ->assertNotFound()->assertJsonPath('code', 'public_series_not_available');
    }

    public function test_public_endpoint_rejects_unknown_identity_unexpected_fields_and_is_throttled(): void
    {
        $fixture = $this->publicSeoFixture();

        $this->postJson('https://indices.test/not-a-public-page/calculate', [
            'start_period' => '2025-01',
            'end_period' => '2025-02',
        ])->assertNotFound()->assertJsonPath('code', 'public_series_not_available');
        $this->postJson('https://indices.test/BAD_slug/calculate', [
            'start_period' => '2025-01',
            'end_period' => '2025-02',
        ])->assertNotFound();

        $this->postJson('https://indices.test/'.$fixture['page']->slug.'/calculate', [
            'start_period' => '2025-01',
            'end_period' => '2025-02',
            'series_id' => $fixture['series']->id,
        ])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
        foreach (range(1, 20) as $attempt) {
            $this->calculate($fixture, '2025-01', '2025-02')->assertOk();
        }
        $this->calculate($fixture, '2025-01', '2025-02')->assertTooManyRequests();
    }

    public function test_private_calculation_route_and_sitemap_contracts_are_unchanged(): void
    {
        $fixture = $this->publicSeoFixture();

        $this->postJson('/api/indices/calculate', [
            'series_public_id' => $fixture['series']->public_id,
            'start_period' => '2025-01',
            'end_period' => '2025-02',
        ])->assertUnauthorized();

        $this->get('https://indices.test/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/calculate', false)
            ->assertDontSee('?q=', false);
        $this->get('https://indices.test/31-02-10-140')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://indices.test/31-02-10-140">', false)
            ->assertSee('<meta name="robots" content="index,follow">', false);
    }

    public function test_browser_form_post_redirects_to_the_canonical_detail_without_session_state(): void
    {
        $fixture = $this->publicSeoFixture();

        $response = $this->withHeader('Accept', 'text/html')->post(
            'https://indices.test/'.$fixture['page']->slug.'/calculate',
            [
                'start_period' => '2025-01',
                'end_period' => '2025-02',
            ],
        );

        $response->assertStatus(303)
            ->assertRedirect('https://indices.test/'.$fixture['page']->slug);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertFalse($response->headers->has('set-cookie'));

        $this->get('https://indices.test/'.$fixture['page']->slug.'/calculate')
            ->assertNotFound();
    }

    private function calculate(
        array $fixture,
        string $start,
        string $end,
        ?string $amount = null,
    ) {
        $payload = ['start_period' => $start, 'end_period' => $end];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        return $this->postJson(
            'https://indices.test/'.$fixture['page']->slug.'/calculate',
            $payload,
        );
    }
}
