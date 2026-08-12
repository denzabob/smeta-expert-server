<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Imports\StatisticalDatasetActiveImport;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Observations\StatisticalObservation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsUserCalculationFixture;
use Tests\TestCase;

class PriceIndicesCalculationApiTest extends TestCase
{
    use BuildsUserCalculationFixture;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('price_indices.enabled', true);
        Config::set('price_indices.admin_only', true);
    }

    public function test_calculation_access_is_unauthorized_for_guest_and_forbidden_for_ordinary_user(): void
    {
        $payload = ['series_public_id' => '00000000-0000-4000-8000-000000000000', 'start_period' => '2024-01', 'end_period' => '2024-01'];
        $this->postJson('/api/indices/calculate', $payload)->assertUnauthorized();
        $this->actingAsPriceIndicesRole('user');
        $this->postJson('/api/indices/calculate', $payload)->assertForbidden();
    }

    public function test_same_period_has_identity_coefficient_empty_chain_and_rounded_amount(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture(['2024-01' => '106.8100000000']);

        $this->calculate($fixture, '2024-01', '2024-01', '123.455')
            ->assertOk()
            ->assertJsonPath('data.period.interval_semantics', '(start,end]')
            ->assertJsonPath('data.period.factors_count', 0)
            ->assertJsonPath('data.coefficient_raw', '1.00000000000000000000')
            ->assertJsonPath('data.coefficient', '1.000000000000')
            ->assertJsonPath('data.amount.adjusted_raw', '123.45500000000000000000')
            ->assertJsonPath('data.amount.adjusted', '123.46')
            ->assertJsonCount(0, 'data.chain');
    }

    public function test_one_factor_uses_end_observation_and_excludes_start_observation(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture([
            '2024-01' => '106.8100000000',
            '2024-02' => '100.1900000000',
        ]);

        $this->calculate($fixture, '2024-01', '2024-02')
            ->assertOk()
            ->assertJsonPath('data.period.factors_count', 1)
            ->assertJsonPath('data.coefficient_raw', '1.00190000000000000000')
            ->assertJsonPath('data.chain.0.period', '2024-02')
            ->assertJsonPath('data.chain.0.index', '100.1900000000')
            ->assertJsonPath('data.chain.0.factor', '1.00190000000000000000');
    }

    public function test_exact_growth_decrease_and_mixed_chains_are_decimal_safe(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $growth = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '110.0000000000',
            '2024-03' => '120.0000000000',
        ]);
        $this->calculate($growth, '2024-01', '2024-03', '1000.00')
            ->assertOk()
            ->assertJsonPath('data.coefficient_raw', '1.32000000000000000000')
            ->assertJsonPath('data.coefficient', '1.320000000000')
            ->assertJsonPath('data.amount.adjusted', '1320.00')
            ->assertJsonPath('data.chain.1.running_coefficient', '1.320000000000');

        $mixed = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '105.0000000000',
            '2024-03' => '95.0000000000',
        ], '31.02.10.150');
        $this->calculate($mixed, '2024-01', '2024-03')
            ->assertOk()
            ->assertJsonPath('data.coefficient_raw', '0.99750000000000000000')
            ->assertJsonPath('data.chain.1.factor', '0.95000000000000000000');
    }

    public function test_calculation_returns_reproducible_provenance_without_internal_paths(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '101.0000000000',
        ], '05.10.10.101.АГ', 'Локальный товар');

        $response = $this->calculate($fixture, '2024-01', '2024-02')->assertOk()
            ->assertJsonPath('data.series.classifier_item.item_code', '05.10.10.101.АГ')
            ->assertJsonPath('data.provenance.import.public_id', $fixture['import']->public_id)
            ->assertJsonPath('data.provenance.source_file.public_id', $fixture['sourceFile']->public_id)
            ->assertJsonPath('data.provenance.source_file.original_filename', 'producer_indices.xlsx')
            ->assertJsonPath('data.provenance.source_file.sha256', str_repeat('a', 64))
            ->assertJsonPath('data.chain.0.source.sheet', '16')
            ->assertJsonPath('data.chain.0.source.column', 'D');

        $payload = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('stored_path', $payload);
        $this->assertStringNotContainsString('dataset_id', $payload);
        $this->assertStringNotContainsString('import_id', $payload);
    }

    public function test_gap_and_null_value_block_partial_calculation(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $gap = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '101.0000000000',
            '2024-04' => '102.0000000000',
        ]);
        $this->calculate($gap, '2024-01', '2024-04')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'incomplete_observation_chain')
            ->assertJsonPath('details.missing_periods.0', '2024-03');

        $null = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => null,
        ], '31.02.10.160');
        $this->calculate($null, '2024-01', '2024-02')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'incomplete_observation_chain')
            ->assertJsonPath('details.missing_value_periods.0', '2024-02');
    }

    public function test_request_availability_and_eligibility_errors_are_stable(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '101.0000000000',
        ]);

        $this->calculate($fixture, '2024-02', '2024-01')->assertUnprocessable()->assertJsonPath('code', 'invalid_period_range');
        $this->calculate($fixture, '2023-12', '2024-01')->assertUnprocessable()->assertJsonPath('code', 'period_before_available_range');
        $this->calculate($fixture, '2024-01', '2024-03')->assertUnprocessable()->assertJsonPath('code', 'period_after_available_range');

        foreach (['0', '-1', '1e10', 'NaN', '1,20', '1 000'] as $invalid) {
            $this->calculate($fixture, '2024-01', '2024-02', $invalid)
                ->assertUnprocessable()->assertJsonPath('code', 'invalid_base_amount');
        }
        foreach (['2024-1', '01.2024', '2024/01', '2024-13', '2024-01-01'] as $invalidPeriod) {
            $this->calculate($fixture, $invalidPeriod, '2024-02')
                ->assertUnprocessable()->assertJsonPath('code', 'invalid_period_range');
        }

        $unsupported = $this->calculationFixture(['2024-01' => '100.0000000000'], '31.02.10.170', comparisonBasis: 'year_over_year');
        $this->calculate($unsupported, '2024-01', '2024-01')
            ->assertUnprocessable()->assertJsonPath('code', 'unsupported_series_calculation');
    }

    public function test_no_active_and_historical_only_series_are_not_calculable(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $noActive = $this->calculationFixture(['2024-01' => '100.0000000000'], active: false);
        $this->calculate($noActive, '2024-01', '2024-01')
            ->assertStatus(409)->assertJsonPath('code', 'no_active_publication');

        $active = $this->calculationFixture(['2024-01' => '100.0000000000'], '31.02.10.180');
        $historicalItem = \App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem::factory()->create([
            'dataset_id' => $active['dataset']->id,
            'item_code' => '31.02.10.181',
        ]);
        $historicalSeries = \App\Domain\PriceIndices\Domain\Series\StatisticalSeries::factory()->create([
            'dataset_id' => $active['dataset']->id,
            'indicator_id' => $active['indicator']->id,
            'classifier_item_id' => $historicalItem->id,
            'territory_id' => $active['territory']->id,
        ]);
        $historicalImport = StatisticalImport::factory()->create([
            'dataset_id' => $active['dataset']->id,
            'source_file_id' => $active['sourceFile']->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Superseded,
        ]);
        StatisticalObservation::factory()->create([
            'import_id' => $historicalImport->id,
            'series_id' => $historicalSeries->id,
            'source_file_id' => $active['sourceFile']->id,
            'period_start' => '2024-01-01',
            'value' => '200.0000000000',
        ]);
        $historicalPayload = $active;
        $historicalPayload['series'] = $historicalSeries;
        $this->calculate($historicalPayload, '2024-01', '2024-01')
            ->assertNotFound()->assertJsonPath('code', 'series_not_available');
    }

    public function test_non_positive_published_observation_is_safe_integrity_error(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '0.0000000000',
        ]);

        $this->calculate($fixture, '2024-01', '2024-02')
            ->assertStatus(500)
            ->assertJsonPath('code', 'calculation_integrity_error')
            ->assertJsonMissingPath('data');
    }

    public function test_active_pointer_is_snapshotted_and_switch_changes_only_new_calculations(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture([
            '2024-01' => '100.0000000000',
            '2024-02' => '110.0000000000',
        ]);
        $first = $this->calculate($fixture, '2024-01', '2024-02')->assertOk();

        $secondImport = StatisticalImport::factory()->create([
            'dataset_id' => $fixture['dataset']->id,
            'source_file_id' => $fixture['sourceFile']->id,
            'attempt_no' => 2,
            'status' => StatisticalImportStatus::Published,
            'published_at' => '2026-08-01 10:00:00',
        ]);
        foreach (['2024-01' => '100.0000000000', '2024-02' => '120.0000000000'] as $period => $value) {
            StatisticalObservation::factory()->create([
                'import_id' => $secondImport->id,
                'series_id' => $fixture['series']->id,
                'source_file_id' => $fixture['sourceFile']->id,
                'period_start' => $period.'-01',
                'value' => $value,
            ]);
        }
        $fixture['import']->update(['status' => StatisticalImportStatus::Superseded]);
        StatisticalDatasetActiveImport::query()->where('dataset_id', $fixture['dataset']->id)->update([
            'import_id' => $secondImport->id,
            'published_at' => '2026-08-01 10:00:00',
        ]);

        $second = $this->calculate($fixture, '2024-01', '2024-02')->assertOk();
        $this->assertSame($fixture['import']->public_id, $first->json('data.provenance.import.public_id'));
        $this->assertSame('1.10000000000000000000', $first->json('data.coefficient_raw'));
        $this->assertSame($secondImport->public_id, $second->json('data.provenance.import.public_id'));
        $this->assertSame('1.20000000000000000000', $second->json('data.coefficient_raw'));
    }

    public function test_long_control_chains_have_bounded_queries_and_expected_factor_counts(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $values = $this->monthlyValues('2021-01', '2026-06');
        $values['2024-02'] = '100.1900000000';
        $fixture = $this->calculationFixture($values);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $short = $this->calculate($fixture, '2024-01', '2026-06')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDOUT, "\nPriceIndices 29-factor query count: {$queryCount}\n");

        $short->assertJsonPath('data.period.factors_count', 29)
            ->assertJsonPath('data.chain.0.period', '2024-02')
            ->assertJsonPath('data.chain.28.period', '2026-06')
            ->assertJsonPath('data.coefficient_raw', '1.00190000000000000000');
        $this->assertLessThanOrEqual(12, $queryCount);

        $this->calculate($fixture, '2021-01', '2026-06')->assertOk()
            ->assertJsonPath('data.period.factors_count', 65)
            ->assertJsonPath('data.chain.0.period', '2021-02')
            ->assertJsonPath('data.chain.64.period', '2026-06')
            ->assertJsonPath('data.coefficient_raw', '1.00190000000000000000');
    }

    public function test_synchronous_calculation_timings_are_bounded_across_chain_lengths(): void
    {
        $this->actingAsPriceIndicesRole('admin');
        $fixture = $this->calculationFixture($this->monthlyValues('2021-01', '2026-06'));
        $cases = [
            'same' => ['2026-06', '2026-06'],
            'one' => ['2026-05', '2026-06'],
            'twelve' => ['2025-06', '2026-06'],
            'twenty_nine' => ['2024-01', '2026-06'],
            'sixty_five' => ['2021-01', '2026-06'],
        ];
        $timings = [];
        foreach ($cases as $name => [$start, $end]) {
            $started = hrtime(true);
            $this->calculate($fixture, $start, $end)->assertOk();
            $timings[$name] = intdiv(hrtime(true) - $started, 1_000_000);
            $this->assertLessThan(5_000, $timings[$name]);
        }
        fwrite(STDOUT, "\nPriceIndices calculation timings (ms): ".json_encode($timings, JSON_THROW_ON_ERROR)."\n");
    }

    private function calculate(array $fixture, string $start, string $end, ?string $baseAmount = null)
    {
        $payload = [
            'series_public_id' => $fixture['series']->public_id,
            'start_period' => $start,
            'end_period' => $end,
        ];
        if ($baseAmount !== null) {
            $payload['base_amount'] = $baseAmount;
        }

        return $this->postJson('/api/indices/calculate', $payload);
    }

    /** @return array<string, string> */
    private function monthlyValues(string $from, string $to): array
    {
        $values = [];
        $cursor = new \DateTimeImmutable($from.'-01');
        $end = new \DateTimeImmutable($to.'-01');
        while ($cursor <= $end) {
            $values[$cursor->format('Y-m')] = '100.0000000000';
            $cursor = $cursor->modify('first day of next month');
        }

        return $values;
    }
}
